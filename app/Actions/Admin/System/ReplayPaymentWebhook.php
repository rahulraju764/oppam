<?php

declare(strict_types=1);

namespace App\Actions\Admin\System;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Actions\Billing\HandlePaymentWebhook;
use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayPaymentData;
use App\Exceptions\Admin\WebhookReplayRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\AdminUser;
use App\Models\PaymentWebhookEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\RazorpayWebhookPayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * A12 "webhook replay" (P9.2b2, `system.webhooks.replay`, super admin by default): re-run a stored
 * payment webhook whose processing never finished. CLAUDE.md rule 5 (payments / webhooks).
 *
 *  - The stored payload is redacted, so its signature can't be checked again; it was verified when it
 *    arrived (HandlePaymentWebhook stores only signature-valid events). As a second check (owner,
 *    2026-11-22) a PAYMENT event replays only when the gateway still lists that payment for the order
 *    with the same amount, currency and status — not listed / different → refused; gateway down → refused. Refund events
 *    skip this: SettleRefund asks the gateway itself.
 *  - One replay of a row at a time (cache lock); processing itself is idempotent (payment id).
 *  - Reason required; at most 10 replays an admin an hour; every replay that reaches the gateway
 *    check is audited — done, refused or failed — with the event id and outcome, never the payload.
 */
final class ReplayPaymentWebhook
{
    use AuthorizesBilling;

    public const PER_HOUR = 10;

    public function __construct(
        private readonly HandlePaymentWebhook $webhooks,
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the processing outcome (activated, payment_failed, refund_settled, ignored, …)
     *
     * @throws ModelNotFoundException unknown event (→ 404)
     * @throws WebhookReplayRefused
     */
    public function handle(AdminUser $admin, string $gateway, string $eventId, string $reason): string
    {
        $this->authorizeFor($admin, 'system.webhooks.replay');
        $reason = $this->validatedReason($reason);

        $event = PaymentWebhookEvent::query()->where('gateway', $gateway)->where('event_id', $eventId)->firstOrFail();
        if ($event->processed_at !== null) {
            throw WebhookReplayRefused::alreadyProcessed();
        }

        $limit = 'webhook-replay:'.$admin->id;
        if (RateLimiter::tooManyAttempts($limit, self::PER_HOUR)) {
            throw WebhookReplayRefused::tooMany();
        }
        RateLimiter::hit($limit, 3600);

        $lock = Cache::lock('webhook-replay:'.$event->id, 120);
        if (! $lock->get()) {
            throw WebhookReplayRefused::inProgress();
        }

        try {
            return $this->replay($admin, $event->refresh(), $reason);
        } finally {
            $lock->release();
        }
    }

    private function replay(AdminUser $admin, PaymentWebhookEvent $event, string $reason): string
    {
        if ($event->processed_at !== null) {   // processed while we waited for the lock
            throw WebhookReplayRefused::alreadyProcessed();
        }

        $before = ['processed_at' => null, 'failed_attempts' => $event->failed_attempts];

        try {
            $this->confirmWithGateway($event);
        } catch (WebhookReplayRefused $refused) {
            $this->record('system.webhook_replay_refused', $admin, $event, $before, ['refused' => $refused->getCode()], $reason);

            throw $refused;
        }

        $event->forceFill(['replayed_at' => now(), 'replayed_by' => $admin->id])->save();

        try {
            $outcome = $this->webhooks->process($event);
        } catch (Throwable $e) {
            report($e);
            $this->record('system.webhook_replay_failed', $admin, $event, $before, ['error' => $e::class], $reason);

            throw WebhookReplayRefused::failedAgain($e::class);
        }

        $this->record('system.webhook_replayed', $admin, $event, $before, ['outcome' => $outcome], $reason);

        return $outcome;
    }

    /** @throws WebhookReplayRefused */
    private function confirmWithGateway(PaymentWebhookEvent $event): void
    {
        try {
            $data = RazorpayWebhookPayload::parse($event->payload);
        } catch (InvalidArgumentException) {
            throw WebhookReplayRefused::unreadable();   // it parsed on arrival; refused and audited rather than an error page
        }
        $payment = $data->payment;

        if ($payment === null || ($data->refund !== null && str_starts_with($data->event, 'refund.'))) {
            return;   // no payment to confirm (ignored) / a refund — SettleRefund asks the gateway itself
        }

        try {
            $listed = $this->gateway->paymentsForOrder($payment->orderId);
        } catch (PaymentGatewayUnavailable) {
            throw WebhookReplayRefused::gatewayUnavailable();
        }

        // The gateway must list the SAME payment: id, order, amount, currency and status (review round 1).
        $confirmed = array_filter($listed, fn (GatewayPaymentData $p): bool => $p->id === $payment->id && $p->orderId === $payment->orderId
            && $p->amountPaise === $payment->amountPaise && $p->currency === $payment->currency && $p->status === $payment->status);
        if ($confirmed === []) {
            throw WebhookReplayRefused::notConfirmed();
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(string $action, AdminUser $admin, PaymentWebhookEvent $event, array $before, array $after, string $reason): void
    {
        $this->audit->record($action, $event, $before, $after + ['event' => $event->event], reason: $reason, actor: $admin, subjectLabel: $event->event_id);
    }
}
