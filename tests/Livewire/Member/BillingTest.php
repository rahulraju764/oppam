<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayOrderData;
use App\Enums\OrderStatus;
use App\Enums\PlanCode;
use App\Livewire\Member\Billing\Checkout;
use App\Livewire\Member\Billing\CheckoutSuccess;
use App\Livewire\Member\Chat\Thread;
use App\Livewire\Public\Plans;
use App\Models\Coupon;
use App\Models\Order;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P5.1 — the membership screens (PRD M10, template package.php / checkout.php): /plans (durations,
| comparison), Member\Billing\Checkout (server quote, live coupon, GST line, pay), the success page
| (owner only, polls / reconciles), and the chat composer unlocking on `.entitlements.changed`.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('/checkout needs a signed-in member; Free or an unknown plan is a 404', function (): void {
    $this->get(memberUrl('/checkout/gold'))->assertRedirect(memberUrl('/login'));

    $this->actingAs(groom())->get(memberUrl('/checkout/free'))->assertNotFound();
    $this->get(memberUrl('/checkout/platinum'))->assertNotFound();
    $this->get(memberUrl('/checkout/gold?duration=1'))->assertOk()->assertSee('Gold Membership');
});

it('M10: checkout shows the server quote — subtotal, GST 18 %, total — and switches duration', function (): void {
    Livewire::actingAs(groom(), 'web')->test(Checkout::class, ['plan' => 'gold'])
        ->assertSee('Gold Membership')
        ->assertSee('GST (18%)')
        ->assertSeeHtml('&#8377;179.82')
        ->assertSeeHtml('&#8377;1,178.82')
        ->set('duration', 12)
        ->assertSeeHtml('&#8377;8,991')
        ->assertSeeHtml('&#8377;10,609.38')
        ->set('duration', 2)                       // not sold: falls back to the shortest duration
        ->assertSet('duration', 1);
});

it('M10: a coupon is checked live — accepted codes show the discount, others an error', function (): void {
    Coupon::factory()->create(['code' => 'SAVE10']);

    Livewire::actingAs(groom(), 'web')->test(Checkout::class, ['plan' => 'gold'])
        ->set('coupon', 'nope')
        ->assertHasErrors(['coupon'])
        ->assertSee('This code isn’t valid.')
        ->assertSet('appliedCoupon', null)
        ->set('coupon', 'save10')
        ->assertHasNoErrors()
        ->assertSet('appliedCoupon', 'SAVE10')
        ->assertSee('Code SAVE10 applied')
        ->assertSee('Coupon (SAVE10)')
        ->assertSeeHtml('&#8377;1,060.94')                    // 899.10 + 161.84 GST
        ->call('removeCoupon')
        ->assertSet('appliedCoupon', null)
        ->assertSeeHtml('&#8377;1,178.82');
});

it('security: the plan key, the accepted coupon and the order code can\'t be changed from the browser', function (string $property, string $value): void {
    Livewire::actingAs(groom(), 'web')->test(Checkout::class, ['plan' => 'silver'])->set($property, $value);
})->with([
    'plan' => ['plan', 'DIAMOND'],
    'accepted coupon' => ['appliedCoupon', 'FREE100'],
    'order' => ['orderCode', 'ORD100001'],
])->throws(CannotUpdateLockedPropertyException::class);

it('F04 (fake gateway): pay creates the order, "Complete test payment" activates it and goes to the success page', function (): void {
    $member = groom();

    $checkout = Livewire::actingAs($member, 'web')->test(Checkout::class, ['plan' => 'gold'])
        ->call('pay')
        ->assertSee('Complete test payment');
    $order = Order::query()->sole();
    expect($order->total_paise)->toBe(117_882);

    $checkout->call('completeTestPayment', true)
        ->assertRedirect(route('member.billing.success', ['order' => $order->code]));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('with Razorpay, pay hands the browser the server\'s order and amount — nothing else', function (): void {
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
            return 'rzp_test_public';
        }

        public function createOrder(string $receipt, int $amountPaise, array $notes): GatewayOrderData
        {
            return new GatewayOrderData('order_rzp123', $amountPaise, 'INR');
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

    Livewire::actingAs(groom(), 'web')->test(Checkout::class, ['plan' => 'silver'])
        ->call('pay')
        ->assertDispatched('razorpay-open', function (string $event, array $params): bool {
            return $params['options']['key'] === 'rzp_test_public'
                && $params['options']['order_id'] === 'order_rzp123'
                && $params['options']['amount'] === 58_882
                && str_contains($params['successUrl'], '/billing/success/ORD')
                && ! array_key_exists('secret', $params['options']);
        })
        ->assertDontSee('Complete test payment');
});

it('A01 security: a support session sees checkout but can\'t pay', function (): void {
    $member = groom();
    chatImpersonate($member);

    Livewire::actingAs($member, 'web')->test(Checkout::class, ['plan' => 'gold'])
        ->assertSee('Not available while our team is viewing this account for support.')
        ->call('pay')
        ->assertSet('orderCode', null);

    expect(Order::query()->count())->toBe(0);
});

it('decisions 2026-10-21: checkout explains an extension, an upgrade and a refused downgrade', function (): void {
    $member = chatOnPlan(groom(), PlanCode::Gold);

    Livewire::actingAs($member, 'web')->test(Checkout::class, ['plan' => 'gold'])->assertSee('These months are added after that');
    Livewire::actingAs($member, 'web')->test(Checkout::class, ['plan' => 'diamond'])->assertSee('Upgrading ends your Gold plan');
    Livewire::actingAs($member, 'web')->test(Checkout::class, ['plan' => 'silver'])
        ->assertSee('You can choose a lower plan after it ends.')
        ->assertSeeHtml('x-bind:disabled="opening || true"')   // Alpine keeps the server's disabled state
        ->assertSeeHtml('disabled')
        ->call('pay');

    expect(Order::query()->count())->toBe(0);
});

it('F04: the success page is the member\'s own order only, and shows waiting → paid', function (): void {
    $member = groom();
    $order = checkoutOrder($member);

    $this->actingAs(bride())->get(memberUrl('/billing/success/'.$order->code))->assertNotFound();

    $page = Livewire::actingAs($member, 'web')->test(CheckoutSuccess::class, ['order' => $order->code])
        ->assertSee('Confirming your payment…')
        ->assertSeeHtml('wire:poll.2s="check"');

    capturePayment($order);

    $page->call('$refresh')
        ->assertSee('Payment received')
        ->assertSee('Your Gold membership is active until')
        ->assertSee('OPM-')
        ->assertSee('Download invoice')
        ->assertDontSeeHtml('wire:poll');
});

it('F04: the success page asks the gateway itself after a few polls when no webhook comes', function (): void {
    $member = groom();
    $order = checkoutOrder($member);
    fakeGateway()->capture((string) $order->gateway_order_id);   // paid at the gateway, webhook lost

    Livewire::actingAs($member, 'web')->test(CheckoutSuccess::class, ['order' => $order->code])
        ->call('check')->call('check')
        ->assertSee('Confirming your payment…')
        ->call('check')
        ->assertSee('Payment received');
});

it('the success page shows a failed payment with a retry', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Silver, 3);
    $failed = fakeGateway()->fail((string) $order->gateway_order_id);
    app(App\Actions\Billing\HandlePaymentWebhook::class)->handle($failed['body'], $failed['signature'], $failed['event_id']);

    Livewire::actingAs($member, 'web')->test(CheckoutSuccess::class, ['order' => $order->code])
        ->assertSee('Payment didn’t go through')
        ->assertSeeHtml(e(route('member.checkout', ['plan' => 'silver', 'duration' => 3])));
});

it('M10: /plans prices every duration from plan_prices and shows the comparison table', function (): void {
    Livewire::test(Plans::class)
        ->assertSee('Billed monthly')
        ->assertSee('Compare plans')
        ->assertSeeInOrder(['Interests per month', '3', '25', '100', 'Unlimited'])
        ->set('duration', 12)
        ->assertSee('Billed as ₹17,991 for 12 months')
        ->assertSee('save 25%')
        ->assertSeeHtml(e(route('member.checkout', ['plan' => 'diamond', 'duration' => 12])));
});

it('F04 / M10 acceptance: the Free composer re-renders on .entitlements.changed and unlocks after payment', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);   // the bride is on Free

    $thread = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertSee('Upgrade to chat')
        ->assertDontSeeHtml('id="msgText"');

    expect(array_keys($thread->instance()->getListeners()))
        ->toContain('echo-private:App.Models.User.'.$bride->id.',.entitlements.changed');

    capturePayment(checkoutOrder($bride, PlanCode::Silver));

    $thread->dispatch('echo-private:App.Models.User.'.$bride->id.',.entitlements.changed', ['plan' => 'SILVER'])
        ->assertSeeHtml('id="msgText"')
        ->assertDontSee('Upgrade to chat');
});
