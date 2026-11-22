<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentGateway;
use App\Data\Billing\WebhookEventData;
use App\Enums\PaymentStatus;
use App\Exceptions\Billing\InvalidWebhook;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\RazorpayWebhookPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * A payment webhook (F04 "Webhook payment.captured (signature verified, idempotent)"):
 *  1. the signature over the RAW body must verify, else nothing is written (InvalidWebhook → 400);
 *  2. the event is stored once per gateway event id (payer contact details redacted) — a
 *     redelivery of a processed event is answered "replayed" without acting again;
 *  3. payment.captured / order.paid → ActivateSubscription (itself idempotent on the payment id);
 *     payment.failed → RecordFailedPayment; refund.* → SettleRefund; anything else is stored and
 *     ignored.
 */
final class HandlePaymentWebhook
{
    public const REPLAYED = 'replayed';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ActivateSubscription $activate,
        private readonly RecordFailedPayment $failed,
        private readonly SettleRefund $refunds,
    ) {}

    /** @throws InvalidWebhook */
    public function handle(string $body, string $signature, ?string $eventId): string
    {
        if (! $this->gateway->verifyWebhookSignature($body, $signature)) {
            throw InvalidWebhook::signature();
        }

        try {
            $data = RazorpayWebhookPayload::parse($body);
        } catch (InvalidArgumentException) {
            throw InvalidWebhook::body();
        }

        // Razorpay sends X-Razorpay-Event-Id; without one, the body itself identifies the delivery.
        $eventId = $eventId !== null && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $eventId) === 1
            ? $eventId
            : 'body_'.substr(hash('sha256', $body), 0, 48);

        PaymentWebhookEvent::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'gateway' => $this->gateway->name(),
            'event_id' => $eventId,
            'event' => substr($data->event, 0, 60),
            'payload' => RazorpayWebhookPayload::redacted($body),   // payer email / phone / UPI id never stored
            'gateway_order_id' => $this->orderIdOf($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = PaymentWebhookEvent::query()->where('gateway', $this->gateway->name())->where('event_id', $eventId)->firstOrFail();

        return $this->process($stored, $data);
    }

    /**
     * Act on a STORED event (steps 3–4): the webhook request above, or an admin replay
     * (ReplayPaymentWebhook) — a replay has no signature to check (the payload is stored redacted),
     * so it reaches here only for a row that was signature-verified when it arrived.
     * A processed event is answered "replayed" without acting again. If processing throws, the
     * attempt is counted on the row (exception class only) and the exception is rethrown, so the
     * webhook still answers 500 and the gateway retries.
     */
    public function process(PaymentWebhookEvent $stored, ?WebhookEventData $data = null): string
    {
        if ($stored->processed_at !== null) {
            return self::REPLAYED;
        }

        try {
            $data ??= RazorpayWebhookPayload::parse($stored->payload);

            $payment = $data->payment;
            $outcome = match (true) {
                $data->refund !== null && str_starts_with($data->event, 'refund.') => $this->refunds->handle($data->refund),
                $payment === null => self::IGNORED,
                in_array($data->event, ['payment.captured', 'order.paid'], true) && $payment->status === PaymentStatus::Captured => $this->activate->handle($payment),
                $data->event === 'payment.failed' && $payment->status === PaymentStatus::Failed => $this->failed->handle($payment),
                default => self::IGNORED,
            };
        } catch (Throwable $e) {
            $this->recordFailure($stored, $e);

            throw $e;
        }

        $stored->forceFill(['processed_at' => now(), 'outcome' => $outcome])->save();

        return $outcome;
    }

    /** Count the failed attempt in its own write; a failure to record it must not hide the original error. */
    private function recordFailure(PaymentWebhookEvent $stored, Throwable $e): void
    {
        rescue(fn () => PaymentWebhookEvent::query()->whereKey($stored->getKey())->update([
            'failed_attempts' => DB::raw('failed_attempts + 1'),
            'last_failed_at' => now(),
            'last_error' => mb_substr($e::class, 0, 120),
            'updated_at' => now(),
        ]));
    }

    /** The order this delivery belongs to, so A06 shows it there; a refund event names only its payment. */
    private function orderIdOf(WebhookEventData $data): ?string
    {
        $id = $data->gatewayOrderId ?? ($data->refund === null ? null : Payment::query()->where('gateway_payment_id', $data->refund->paymentId)
            ->first()?->order()->value('gateway_order_id'));

        return $id === null ? null : substr($id, 0, 64);
    }
}
