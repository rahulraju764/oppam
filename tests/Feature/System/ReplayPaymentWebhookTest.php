<?php

declare(strict_types=1);

use App\Actions\Admin\System\ReplayPaymentWebhook;
use App\Actions\Billing\HandlePaymentWebhook;
use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Exceptions\Admin\WebhookReplayRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/*
| P9.2b2 — A12 "webhook replay" (`system.webhooks.replay`; CLAUDE.md rule 5): re-run a stored payment
| webhook whose processing threw. The payload is stored redacted, so there is no signature to check
| again — a payment event replays only when the gateway still lists that payment with that status
| (owner 2026-11-22). Reason, rate limit, one replay of a row at a time, audited either way.
*/

beforeEach(function (): void {
    $this->seed(Database\Seeders\PlansSeeder::class);
    seedAdminRoles();
    Cache::flush();
    Illuminate\Support\Facades\Notification::fake();
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Billing\GenerateInvoicePdf::class]);
    Illuminate\Support\Facades\Event::fake([App\Events\Billing\EntitlementsChanged::class, App\Events\Billing\OrderPaid::class]);
    $this->failing = false;
    Payment::creating(function (): void {
        if ($this->failing) {
            throw new RuntimeException('processing broke');
        }
    });
});

/** A capture whose processing threw once: stored, not processed. Returns [order, stored event]. */
function stuckCapture(?array $delivery = null, ?App\Models\Order $order = null): array
{
    $order ??= checkoutOrder(groom());
    $delivery ??= fakeGateway()->capture((string) $order->gateway_order_id);
    test()->failing = true;
    postWebhook($delivery)->assertStatus(500);
    test()->failing = false;

    return [$order, PaymentWebhookEvent::query()->where('event_id', $delivery['event_id'])->sole()];
}

function replay(App\Models\AdminUser $admin, PaymentWebhookEvent $event, string $reason = 'Gateway outage on 22 Nov', ?ReplayPaymentWebhook $action = null): string
{
    return ($action ?? app(ReplayPaymentWebhook::class))->handle($admin, $event->gateway, $event->event_id, $reason);
}

it('A12 replay: a stuck capture is replayed — the plan activates, the row is processed, the replay audited with the reason', function (): void {
    [$order, $event] = stuckCapture();
    $admin = adminWithRole('super_admin');

    expect(replay($admin, $event))->toBe('activated');

    $event->refresh();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($event->processed_at)->not->toBeNull()->and($event->outcome)->toBe('activated')
        ->and($event->replayed_by)->toBe($admin->id)->and($event->replayed_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'system.webhook_replayed')->sole();
    expect($audit->reason)->toBe('Gateway outage on 22 Nov')
        ->and($audit->subject_label)->toBe($event->event_id)
        ->and($audit->after)->toMatchArray(['outcome' => 'activated', 'event' => 'payment.captured'])
        ->and(json_encode($audit->getAttributes()))->not->toContain('"amount"')->not->toContain($order->gateway_order_id);
});

it('A12 replay (owner 1): a payment the gateway doesn\'t list for the order is refused, audited, and nothing activates', function (): void {
    $order = checkoutOrder(groom());
    $forged = fakeGateway()->webhook('payment.captured', [
        'id' => 'pay_not_at_gateway', 'order_id' => $order->gateway_order_id, 'amount' => $order->total_paise,
        'currency' => 'INR', 'status' => 'captured', 'method' => 'upi',
    ]);
    [, $event] = stuckCapture($forged, $order);

    expect(fn () => replay(adminWithRole('super_admin'), $event))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::NOT_CONFIRMED));

    expect($order->refresh()->status)->toBe(OrderStatus::Created)
        ->and(Subscription::query()->count())->toBe(0)
        ->and($event->refresh()->processed_at)->toBeNull()
        ->and($event->replayed_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'system.webhook_replay_refused')->sole()->after)->toMatchArray(['refused' => WebhookReplayRefused::NOT_CONFIRMED]);
});

it('A12 replay (owner 1): the gateway not answering refuses the replay', function (): void {
    [$order, $event] = stuckCapture();
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('paymentsForOrder')->andThrow(new PaymentGatewayUnavailable('timeout'));
    $action = new ReplayPaymentWebhook(app(HandlePaymentWebhook::class), $gateway, app(AuditLogger::class));

    expect(fn () => replay(adminWithRole('super_admin'), $event, action: $action))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::GATEWAY_UNAVAILABLE));
    expect($order->refresh()->status)->toBe(OrderStatus::Created);
});

it('A12 replay: a refund event skips the payment check — SettleRefund asks the gateway itself', function (): void {
    $delivery = ['event_id' => 'evt_refund_stuck', 'body' => (string) json_encode(['entity' => 'event', 'event' => 'refund.processed', 'contains' => ['refund'],
        'payload' => ['refund' => ['entity' => ['id' => 'rfnd_unknown_1', 'payment_id' => 'pay_unknown_1', 'amount' => 9900, 'currency' => 'INR', 'status' => 'processed']]]])];
    $event = PaymentWebhookEvent::factory()->create([
        'gateway' => fakeGateway()->name(), 'event_id' => $delivery['event_id'], 'event' => 'refund.processed',
        'payload' => $delivery['body'], 'processed_at' => null, 'outcome' => null, 'failed_attempts' => 1,
    ]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldNotReceive('paymentsForOrder');
    $action = new ReplayPaymentWebhook(app(HandlePaymentWebhook::class), $gateway, app(AuditLogger::class));

    expect(replay(adminWithRole('super_admin'), $event, action: $action))->toBe('refund_unknown')
        ->and($event->refresh()->processed_at)->not->toBeNull();
});

it('A12 replay: processing that fails again is counted, audited as failed, and shown as a refusal', function (): void {
    [, $event] = stuckCapture();
    test()->failing = true;

    expect(fn () => replay(adminWithRole('super_admin'), $event))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::FAILED_AGAIN));

    expect($event->refresh()->failed_attempts)->toBe(2)->and($event->processed_at)->toBeNull()
        ->and($event->replayed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'system.webhook_replay_failed')->sole()->after)->toMatchArray(['error' => RuntimeException::class]);
});

it('A12 replay: an already processed event is refused; replaying twice activates once', function (): void {
    [, $event] = stuckCapture();
    $admin = adminWithRole('super_admin');

    expect(replay($admin, $event))->toBe('activated');
    expect(fn () => replay($admin, $event))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::ALREADY_PROCESSED));

    expect(Subscription::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

it('A12 replay: a row another replay holds is refused while it runs', function (): void {
    [, $event] = stuckCapture();
    $lock = Cache::lock('webhook-replay:'.$event->id, 120);
    $lock->get();

    expect(fn () => replay(adminWithRole('super_admin'), $event))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::IN_PROGRESS));
    $lock->release();
});

it('A12 replay security: without system.webhooks.replay it is refused (read_only, finance)', function (string $role): void {
    [, $event] = stuckCapture();

    expect(fn () => replay(adminWithRole($role), $event))->toThrow(AuthorizationException::class);
    expect($event->refresh()->processed_at)->toBeNull()->and(AuditLog::query()->where('action', 'like', 'system.webhook_%')->count())->toBe(0);
})->with(['read_only', 'finance']);

it('A12 replay security: an unknown event id or another gateway\'s id is a 404', function (): void {
    [, $event] = stuckCapture();
    $action = app(ReplayPaymentWebhook::class);
    $admin = adminWithRole('super_admin');

    expect(fn () => $action->handle($admin, $event->gateway, 'evt_does_not_exist', 'A good reason'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $action->handle($admin, 'razorpay_other', $event->event_id, 'A good reason'))->toThrow(ModelNotFoundException::class);
});

it('A12 replay validation: a reason of under 5 characters is refused before anything runs', function (): void {
    [, $event] = stuckCapture();

    expect(fn () => replay(adminWithRole('super_admin'), $event, reason: 'oops'))->toThrow(ValidationException::class);
    expect($event->refresh()->replayed_at)->toBeNull();
});

it('A12 replay rate limit: the 11th replay in an hour is refused', function (): void {
    [, $event] = stuckCapture();
    $admin = adminWithRole('super_admin');
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('paymentsForOrder')->andReturn([]);   // every attempt refused at the gateway check — still counts
    $action = new ReplayPaymentWebhook(app(HandlePaymentWebhook::class), $gateway, app(AuditLogger::class));

    foreach (range(1, ReplayPaymentWebhook::PER_HOUR) as $_) {
        expect(fn () => replay($admin, $event, action: $action))->toThrow(WebhookReplayRefused::class);
    }

    expect(fn () => replay($admin, $event, action: $action))
        ->toThrow(fn (WebhookReplayRefused $e) => expect($e->getCode())->toBe(WebhookReplayRefused::TOO_MANY));
});
