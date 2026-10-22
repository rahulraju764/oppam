<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanCode;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Events\Billing\OrderPaid;
use App\Jobs\Billing\ExpireUnpaidOrders;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Notifications\Billing\PaymentSucceeded;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
| P5.1 — /webhooks/razorpay → ActivateSubscription (PRD M10, F04): signature first, idempotent on the
| gateway event id AND the payment id, one transaction (order PAID, payment, subscription, premium,
| coupon redemption, invoice), then EntitlementsChanged, payment_success, the invoice PDF job and
| OrderPaid after commit.
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    Event::fake([EntitlementsChanged::class, OrderPaid::class]);
});

it('F04: a signed payment.captured activates the plan in one go', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Gold, 3);

    postWebhook(fakeGateway()->capture((string) $order->gateway_order_id))
        ->assertOk()->assertJson(['status' => 'ok', 'outcome' => 'activated']);

    $order->refresh();
    $subscription = Subscription::query()->where('order_id', $order->id)->sole();
    $profile = $member->profile->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Payment::query()->where('order_id', $order->id)->sole()->status)->toBe(PaymentStatus::Captured)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->source)->toBe(SubscriptionSource::Paid)
        ->and($subscription->duration_months)->toBe(3)
        ->and(CarbonImmutable::instance($subscription->ends_at)->toDateString())->toBe(now()->addMonthsNoOverflow(3)->toDateString())
        ->and($profile->is_premium)->toBeTrue()
        ->and(app(EntitlementService::class)->allows($profile, Entitlement::ChatSend))->toBeTrue()
        ->and(Invoice::query()->where('order_id', $order->id)->sole()->total_paise)->toBe($order->total_paise)
        ->and(PaymentWebhookEvent::query()->sole()->outcome)->toBe('activated');

    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $member->id && $e->planCode === 'GOLD');
    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->orderId === $order->id);
    Notification::assertSentTo($member, PaymentSucceeded::class, fn (PaymentSucceeded $n): bool => $n->orderCode === $order->code);
    Queue::assertPushed(GenerateInvoicePdf::class);
});

it('M10 acceptance: a replayed webhook does not double-activate', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    $delivery = fakeGateway()->capture((string) $order->gateway_order_id);

    postWebhook($delivery)->assertOk()->assertJson(['outcome' => 'activated']);
    postWebhook($delivery)->assertOk()->assertJson(['outcome' => 'replayed']);          // the same delivery again

    // The same payment announced by another event (order.paid after payment.captured), new event id.
    $payment = json_decode($delivery['body'], true)['payload']['payment']['entity'];
    postWebhook(fakeGateway()->webhook('order.paid', $payment))->assertOk()->assertJson(['outcome' => 'duplicate']);

    expect(Subscription::query()->where('profile_id', $member->profile->id)->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(PaymentWebhookEvent::query()->count())->toBe(2);

    Event::assertDispatchedTimes(EntitlementsChanged::class, 1);
    Notification::assertSentToTimes($member, PaymentSucceeded::class, 1);
});

it('security: a wrong signature is a 400 and nothing is written', function (string $signature): void {
    $order = checkoutOrder(groom());
    $delivery = fakeGateway()->capture((string) $order->gateway_order_id);

    postWebhook($delivery, $signature)->assertStatus(400);

    expect(PaymentWebhookEvent::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Created);
})->with([
    'empty' => [''],
    'forged' => [hash_hmac('sha256', 'something else', 'guess')],
]);

it('security: a body changed after signing is refused (the signature covers the raw body)', function (): void {
    $order = checkoutOrder(groom());
    $delivery = fakeGateway()->capture((string) $order->gateway_order_id);
    $delivery['body'] = str_replace('"amount":'.$order->total_paise, '"amount":1', $delivery['body']);

    postWebhook($delivery)->assertStatus(400);
    expect($order->refresh()->status)->toBe(OrderStatus::Created);
});

it('a signed body that isn\'t an event is a 400', function (): void {
    $body = '{"hello":"world"}';
    postWebhook(['body' => $body, 'signature' => hash_hmac('sha256', $body, hash_hmac('sha256', 'fake-payment-gateway', (string) config('app.key'))), 'event_id' => 'evt_x'])
        ->assertStatus(400);
});

it('a capture for an order we don\'t know is acknowledged and recorded, nothing activated', function (): void {
    postWebhook(fakeGateway()->capture('order_unknown123', 50_000))->assertOk()->assertJson(['outcome' => 'unknown_order']);

    expect(Subscription::query()->count())->toBe(0)
        ->and(PaymentWebhookEvent::query()->sole()->outcome)->toBe('unknown_order');
});

it('a second captured payment on a paid order is recorded for refund, never a second plan', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    capturePayment($order);

    expect(capturePayment($order))->toBe('extra_payment')
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(Subscription::query()->count())->toBe(1);
});

it('payment.failed records the attempt and keeps the order open for a retry', function (): void {
    $order = checkoutOrder(groom());

    postWebhook(fakeGateway()->fail((string) $order->gateway_order_id))->assertOk()->assertJson(['outcome' => 'payment_failed']);

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Created);

    expect(capturePayment($order))->toBe('activated');
});

it('an order that expired before the capture still activates — the member paid', function (): void {
    $order = checkoutOrder(groom());
    $this->travel(2)->hours();
    (new ExpireUnpaidOrders)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::Expired);

    expect(capturePayment($order))->toBe('activated')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('decisions 2026-10-21: buying the same plan again adds the months after the current period', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Gold, 1));
    $firstEnd = Subscription::query()->sole()->ends_at;

    capturePayment(checkoutOrder($member, PlanCode::Gold, 3));

    $second = Subscription::query()->latest('ends_at')->first();
    expect($second->starts_at->equalTo($firstEnd))->toBeTrue()
        ->and(CarbonImmutable::instance($second->ends_at)->equalTo(CarbonImmutable::instance($firstEnd)->addMonthsNoOverflow(3)))->toBeTrue()
        ->and(Subscription::query()->where('status', SubscriptionStatus::Active->value)->count())->toBe(2);
});

it('decisions 2026-10-21: an upgrade starts now and cancels the running plan (no proration)', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Silver, 1));

    capturePayment(checkoutOrder($member, PlanCode::Gold, 1));

    $silver = Subscription::query()->whereHas('plan', fn ($q) => $q->where('code', 'SILVER'))->sole();
    expect($silver->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($silver->cancelled_at)->not->toBeNull()
        ->and(app(EntitlementService::class)->plan($member->profile->refresh())->code)->toBe(PlanCode::Gold);
});

it('a lower plan paid while a higher one runs (a race past checkout) queues after it', function (): void {
    $member = groom();
    $silverOrder = checkoutOrder($member, PlanCode::Silver, 1);      // checkout opened while on Free
    capturePayment(checkoutOrder($member, PlanCode::Diamond, 1));     // Diamond paid first, in another tab

    expect(capturePayment($silverOrder))->toBe('activated');
    $diamond = Subscription::query()->whereHas('plan', fn ($q) => $q->where('code', 'DIAMOND'))->sole();
    $silver = Subscription::query()->whereHas('plan', fn ($q) => $q->where('code', 'SILVER'))->sole();

    expect($silver->starts_at->equalTo($diamond->ends_at))->toBeTrue()
        ->and(app(EntitlementService::class)->plan($member->profile->refresh())->code)->toBe(PlanCode::Diamond);
});

it('A06: a coupon is redeemed only when the order is PAID — an abandoned checkout uses nothing up', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'ONCE', 'max_redemptions' => 1]);
    $member = groom();
    $abandoned = checkoutOrder($member, PlanCode::Gold, 1, 'ONCE');
    expect($coupon->refresh()->redemptions_count)->toBe(0);

    capturePayment($abandoned);

    expect(CouponRedemption::query()->sole()->discount_paise)->toBe($abandoned->discount_paise)
        ->and($coupon->refresh()->redemptions_count)->toBe(1)
        ->and(fn () => checkoutOrder(bride(), PlanCode::Gold, 1, 'ONCE'))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('security: the live payload carries the plan code, name and end date only — no ids or amounts', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member));

    Event::assertDispatched(EntitlementsChanged::class, function (EntitlementsChanged $event) use ($member): bool {
        expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$member->id)
            ->and($event->broadcastAs())->toBe('entitlements.changed')
            ->and(array_keys($event->broadcastWith()))->toBe(['plan', 'plan_name', 'until'])
            ->and(json_encode($event->broadcastWith()))->not->toMatch('/[0-9A-HJKMNP-TV-Z]{26}/');

        return true;
    });
});

it('M10: unpaid orders expire hourly; paid ones are untouched', function (): void {
    $member = groom();
    $paid = checkoutOrder($member, PlanCode::Gold, 1);
    capturePayment($paid);
    $open = checkoutOrder($member, PlanCode::Gold, 3);

    $this->travel(31)->minutes();
    (new ExpireUnpaidOrders)->handle();

    expect($open->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($paid->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Order::query()->count())->toBe(2);
});

it('security: payer email, phone and UPI id in the webhook are never stored', function (): void {
    $order = checkoutOrder(groom());
    $delivery = fakeGateway()->webhook('payment.captured', [
        'id' => 'pay_withpayerdata', 'entity' => 'payment', 'amount' => $order->total_paise, 'currency' => 'INR',
        'status' => 'captured', 'order_id' => $order->gateway_order_id, 'method' => 'upi',
        'email' => 'payer@example.com', 'contact' => '+919812345678', 'vpa' => 'payer@okbank',
    ]);

    postWebhook($delivery)->assertOk()->assertJson(['outcome' => 'activated']);

    $stored = PaymentWebhookEvent::query()->sole()->payload;
    expect($stored)->not->toContain('payer@example.com')
        ->and($stored)->not->toContain('9812345678')
        ->and($stored)->not->toContain('payer@okbank')
        ->and($stored)->toContain('[redacted]')
        ->and($stored)->toContain('pay_withpayerdata');
});

it('A06: two open orders that both get paid past a coupon limit are logged for review (the member paid)', function (): void {
    Coupon::factory()->create(['code' => 'ONLYONE', 'max_redemptions' => 1]);
    $first = checkoutOrder(groom(), PlanCode::Gold, 1, 'ONLYONE');
    $second = checkoutOrder(bride(), PlanCode::Gold, 1, 'ONLYONE');   // both opened before either was paid
    Illuminate\Support\Facades\Log::spy();

    capturePayment($first);
    expect(capturePayment($second))->toBe('activated');

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('billing.coupon_over_limit', Mockery::on(fn (array $context): bool => $context['order'] === $second->code));
});
