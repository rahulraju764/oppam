<?php

declare(strict_types=1);

use App\Actions\Billing\SimulateTestPayment;
use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Payments\RazorpayGateway;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P5.1 — "Complete test payment" (SimulateTestPayment) is the one action that activates a paid plan
| without money moving. It must refuse with a real gateway, in production, for another member's
| order, for an order without a gateway order, and in an admin support session — writing nothing.
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function assertNothingActivated(App\Models\Order $order): void
{
    expect($order->refresh()->status)->toBe(OrderStatus::Created)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
}

it('activates the member\'s own order through the signed-webhook path (fake gateway)', function (): void {
    $member = groom();
    $order = checkoutOrder($member);

    expect(app(SimulateTestPayment::class)->handle($member, $order))->toBe('activated')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('security: not available with a real (Razorpay) gateway — 404, nothing written', function (): void {
    $member = groom();
    $order = checkoutOrder($member);   // created on the fake gateway
    app()->instance(PaymentGateway::class, new RazorpayGateway('rzp_test_key', 'secret', 'whsec'));
    app()->forgetInstance(SimulateTestPayment::class);

    expect(fn () => app(SimulateTestPayment::class)->handle($member, $order))->toThrow(NotFoundHttpException::class);
    assertNothingActivated($order);
});

it('security: not available in production — 404, nothing written', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    $simulate = app(SimulateTestPayment::class);   // resolved before the switch: production refuses the fake binding itself
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => $simulate->handle($member, $order))->toThrow(NotFoundHttpException::class);
    assertNothingActivated($order);
});

it('security: another member\'s order is a 404 — nothing written', function (): void {
    $order = checkoutOrder(groom());

    expect(fn () => app(SimulateTestPayment::class)->handle(bride(), $order))->toThrow(NotFoundHttpException::class);
    assertNothingActivated($order);
});

it('security: an order without a gateway order is a 404 — nothing written', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    $order->forceFill(['gateway_order_id' => null])->save();

    expect(fn () => app(SimulateTestPayment::class)->handle($member, $order))->toThrow(NotFoundHttpException::class);
    assertNothingActivated($order);
});

it('A01 security: an admin support session can\'t simulate a payment — nothing written', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    chatImpersonate($member);

    expect(fn () => app(SimulateTestPayment::class)->handle($member, $order))->toThrow(ImpersonationRestricted::class);
    assertNothingActivated($order);
});
