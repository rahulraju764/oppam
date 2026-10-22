<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use Laravel\Dusk\Browser;

/*
| P5.1 — membership screens at phone width (375 px, device emulation): /plans with the duration
| switch and the comparison table, checkout and the success page never scroll sideways, and the
| pay / invoice actions are reachable.
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
});

it('P5.1: plans, checkout and the success page work at 375px without sideways scrolling', function (): void {
    $this->browse(function (Browser $browser): void {
        $groom = duskSecondMember();

        $browser->visit('/plans')
            ->waitForText('Compare plans')
            ->click('.pill-option:nth-child(4)')                 // 12 months
            ->waitForText('for 12 months');
        assertNoHorizontalScroll($browser);

        $browser->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->visit('/checkout/gold?duration=3')
            ->waitForText('Gold Membership')
            ->assertVisible('@checkout-pay');
        assertNoHorizontalScroll($browser);

        $order = checkoutOrder($groom, PlanCode::Gold, 3);
        capturePayment($order);
        $browser->visit('/billing/success/'.$order->code)
            ->waitForText('Payment received')
            ->assertVisible('a[href*="/billing/invoices/OPM-"]');
        assertNoHorizontalScroll($browser);

        // P5.2: /billing — plan card, usage meters, the orders table scrolls inside its own wrapper.
        $browser->visit('/billing')
            ->waitForText('Your usage')
            ->assertSee('Gold Membership')
            ->assertSee($order->code);
        assertNoHorizontalScroll($browser);
    });
});
