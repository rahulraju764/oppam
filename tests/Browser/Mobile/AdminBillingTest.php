<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Enums\PlanCode;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Models\AdminUser;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/*
| P5.3 — A06 in a browser: a second admin approves a refund at 375 px, the plan editor's preview
| follows the typing, and every billing page fits 320 / 768 / 1024 / 1440 px.
*/

/** The finance admin who asks for the refund: removed after the refund that points at it. */
function duskBillingCleanup(): void
{
    duskChatCleanup();
    AdminUser::query()->where('email', 'like', 'dusk.finance.%')->get()->each(fn (AdminUser $admin) => $admin->forceDelete());
    duskAdminCleanup();
}

beforeEach(fn () => duskBillingCleanup());
afterEach(fn () => duskBillingCleanup());

it('A06: a second admin approves a refund at 375px — refunded, credit note on the order page; the plan preview follows the typing', function (): void {
    $this->browse(function (Browser $browser): void {
        $groom = duskSecondMember();
        $order = checkoutOrder($groom, PlanCode::Gold);
        capturePayment($order);
        $payment = Payment::query()->where('order_id', $order->id)->sole();

        (new App\Models\AdminUser)->newQuery();
        $finance = AdminUser::factory()->role('finance')->create(['email' => 'dusk.finance.'.Str::lower(Str::random(8)).'@example.com']);
        $refund = app(ManageRefund::class)->request($finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, $payment->amount_paise, 'Dusk: member asked');

        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/billing/refunds'))->waitForText($refund->code);
        assertNoHorizontalScroll($browser);
        $browser->click('@refund-approve-open')->waitFor('@refund-approve')
            ->click('@refund-approve')->waitForText('Approved and sent to the gateway');
        expect($refund->refresh()->status)->toBe(RefundStatus::Processed);

        $number = CreditNote::query()->where('refund_id', $refund->id)->sole()->number;
        $browser->visit(adminDuskUrl('/billing/orders/'.$order->code))->waitForText($number)->assertSee('Refunded');
        assertNoHorizontalScroll($browser);

        // The preview redraws as the name is typed; nothing is saved.
        $browser->visit(adminDuskUrl('/billing/plans/GOLD'))->waitForText('Enforced entitlements')
            ->clear('#plan-name')->type('#plan-name', 'Gold Preview Only')
            ->waitForTextIn('.pricing-card .plan-name', 'Gold Preview Only');
        assertNoHorizontalScroll($browser);
        expect(App\Models\Plan::query()->where('code', 'GOLD')->sole()->name)->not->toBe('Gold Preview Only');
    });
});

it('Gate D: every billing page fits 320, 768, 1024 and 1440 px without sideways scrolling', function (): void {
    $this->browse(function (Browser $browser): void {
        $groom = duskSecondMember();
        $order = checkoutOrder($groom, PlanCode::Gold);
        capturePayment($order);

        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskSignInAdmin($browser);

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), array_map(adminDuskUrl(...), [
            '/billing/orders', '/billing/orders/'.$order->code, '/billing/orders/stuck', '/billing/refunds', '/billing/reconciliation',
            '/billing/subscriptions', '/billing/plans', '/billing/plans/GOLD', '/billing/coupons',
        ]));
    });
});
