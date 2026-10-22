<?php

declare(strict_types=1);

use App\Actions\Billing\ApplyCoupon;
use App\Actions\Billing\QuoteCheckout;
use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayOrderData;
use App\Enums\OrderStatus;
use App\Enums\PlanChange;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\CheckoutRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Payments\RazorpayGateway;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P5.1 — quoting and creating checkout orders (PRD M10, F04): the amount comes ONLY from the plan
| price row + coupon + GST on the server; refusals (support session, inactive account, downgrade,
| rate limit, no gateway) write nothing.
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

it('F04: quotes a plan from its price row for the duration, + coupon, + 18 % GST', function (): void {
    $member = groom();
    $quote = app(QuoteCheckout::class)->handle($member, PlanCode::Gold, 3);

    expect($quote->price->subtotalPaise)->toBe(269_700)        // decisions 2026-10-21: 3 months at 10 % off
        ->and($quote->price->gstPaise)->toBe(48_546)
        ->and($quote->price->totalPaise)->toBe(318_246)
        ->and($quote->change)->toBe(PlanChange::New)
        ->and($quote->endsAt->diffInMonths($quote->startsAt, absolute: true))->toEqual(3.0);

    $coupon = Coupon::factory()->create(['code' => 'DIWALI20', 'value' => 20]);
    $discounted = app(QuoteCheckout::class)->handle($member, PlanCode::Gold, 3, ' diwali20 ');
    expect($discounted->couponId)->toBe($coupon->id)
        ->and($discounted->price->discountPaise)->toBe(53_940)
        ->and($discounted->price->totalPaise)->toBe(254_597);   // 215,760 + 38,837 GST
});

it('M10 acceptance: tampering with the amount has no effect — the order and the gateway charge come from the plan key', function (): void {
    $member = groom();
    // A browser can only choose a plan KEY, a duration and a coupon code: there is no amount to send.
    $order = checkoutOrder($member, PlanCode::Silver, 1);

    expect($order->total_paise)->toBe(58_882)
        ->and($order->subtotal_paise)->toBe(49_900)
        ->and(fakeGateway()->orderAmount((string) $order->gateway_order_id))->toBe(58_882)
        ->and($order->status)->toBe(OrderStatus::Created)
        ->and($order->code)->toStartWith('ORD');

    // A payment captured for a different amount (e.g. a tampered client-side checkout) activates nothing.
    expect(capturePayment($order, 100))->toBe('amount_mismatch')
        ->and($order->refresh()->status)->toBe(OrderStatus::Created)
        ->and($order->failure_reason)->toBe('amount_mismatch')
        ->and($member->profile->refresh()->is_premium)->toBeFalse();
});

it('a double click returns the same open order; other terms make a new one', function (): void {
    $member = groom();
    $first = checkoutOrder($member, PlanCode::Gold, 1);

    expect(checkoutOrder($member, PlanCode::Gold, 1)->id)->toBe($first->id)
        ->and(checkoutOrder($member, PlanCode::Gold, 3)->id)->not->toBe($first->id)
        ->and(Order::query()->count())->toBe(2);
});

it('A01 security: an admin support session cannot buy for the member — nothing is written', function (): void {
    $member = groom();
    chatImpersonate($member);

    expect(fn () => checkoutOrder($member))->toThrow(ImpersonationRestricted::class)
        ->and(Order::query()->count())->toBe(0);
});

it('security: a suspended or banned account cannot buy', function (UserStatus $status): void {
    $member = groom();
    $member->forceFill(['status' => $status])->save();

    expect(fn () => checkoutOrder($member->refresh()))->toThrow(CheckoutRefused::class)
        ->and(Order::query()->count())->toBe(0);
})->with([UserStatus::Suspended, UserStatus::Banned]);

it('decisions 2026-10-21: a lower plan is refused while a higher one runs', function (): void {
    $member = chatOnPlan(groom(), PlanCode::Diamond);

    try {
        checkoutOrder($member, PlanCode::Silver);
        $this->fail('A downgrade should be refused.');
    } catch (CheckoutRefused $e) {
        expect($e->reason)->toBe(CheckoutRefused::DOWNGRADE)->and($e->getMessage())->toContain('Diamond');
    }

    expect(app(QuoteCheckout::class)->handle($member, PlanCode::Gold, 1)->change)->toBe(PlanChange::Downgrade)
        ->and(app(QuoteCheckout::class)->handle($member, PlanCode::Diamond, 1)->change)->toBe(PlanChange::Extend)
        ->and(Order::query()->count())->toBe(0);
});

it('security: a plan or duration that is not on sale is a 404', function (PlanCode $plan, int $months): void {
    checkoutOrder(groom(), $plan, $months);
})->with([
    'Free' => [PlanCode::Free, 1],
    'no 2-month price' => [PlanCode::Gold, 2],
])->throws(NotFoundHttpException::class);

it('security: a deactivated price is no longer sold', function (): void {
    App\Models\PlanPrice::query()->where('duration_months', 6)->update(['is_active' => false]);

    checkoutOrder(groom(), PlanCode::Gold, 6);
})->throws(NotFoundHttpException::class);

it('rule 10: orders per member per hour are limited (billing.checkouts_max_per_hour)', function (): void {
    Setting::factory()->keyed(SettingKey::BillingCheckoutsMaxPerHour, 3)->create();
    $member = groom();

    foreach ([1, 3, 6] as $months) {
        checkoutOrder($member, PlanCode::Gold, $months);
    }

    expect(fn () => checkoutOrder($member, PlanCode::Gold, 12))->toThrow(CheckoutRefused::class)
        ->and(Order::query()->count())->toBe(3);
});

it('rule 10: coupon codes a member may try are limited, so codes can\'t be guessed', function (): void {
    Setting::factory()->keyed(SettingKey::BillingCouponAttemptsPerHour, 3)->create();
    Coupon::factory()->create(['code' => 'REAL10']);
    $member = groom();

    foreach (['GUESS1', 'GUESS2', 'GUESS3'] as $guess) {
        expect(fn () => app(ApplyCoupon::class)->handle($member, PlanCode::Gold, 1, $guess))->toThrow(ValidationException::class);
    }

    try {
        app(ApplyCoupon::class)->handle($member, PlanCode::Gold, 1, 'REAL10');
        $this->fail('The 4th try should be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['coupon'][0])->toContain('Too many');
    }
});

it('invalid input: an unusable coupon is refused with a reason and no order is made', function (): void {
    Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);

    expect(fn () => checkoutOrder(groom(), PlanCode::Gold, 1, 'OLD'))->toThrow(ValidationException::class)
        ->and(Order::query()->count())->toBe(0);
});

it('no gateway keys: checkout is unavailable and nothing is written', function (): void {
    app()->instance(PaymentGateway::class, new RazorpayGateway('', '', ''));

    expect(fn () => checkoutOrder(groom()))->toThrow(CheckoutRefused::class)
        ->and(Order::query()->count())->toBe(0);
});

it('a gateway outage leaves a FAILED order and a friendly refusal (no gateway text)', function (): void {
    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function name(): string
        {
            return 'razorpay';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function isTestMode(): bool
        {
            return false;
        }

        public function publicKey(): string
        {
            return 'rzp_test_x';
        }

        public function createOrder(string $receipt, int $amountPaise, array $notes): GatewayOrderData
        {
            throw PaymentGatewayUnavailable::because('timeout');
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return false;
        }

        public function paymentsForOrder(string $gatewayOrderId): array
        {
            return [];
        }
    });

    try {
        checkoutOrder(groom());
        $this->fail('The outage should refuse the checkout.');
    } catch (CheckoutRefused $e) {
        expect($e->reason)->toBe(CheckoutRefused::UNAVAILABLE)->and($e->getMessage())->not->toContain('timeout');
    }

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Failed);
});

it('a fake gateway is refused in production', function (): void {
    app()->forgetInstance(PaymentGateway::class);
    app()->detectEnvironment(fn (): string => 'production');

    app(PaymentGateway::class);
})->throws(RuntimeException::class, 'PAYMENT_DRIVER=fake is not allowed in production.');
