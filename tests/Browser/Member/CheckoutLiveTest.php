<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use Laravel\Dusk\Browser;

/*
| P5.1 — the M10 "Done when" in real browsers over Reverb (needs `composer dev`; PAYMENT_DRIVER=fake,
| the default locally): a Free member's chat composer, open in one window, unlocks WITHOUT a reload
| after they pay in another window — checkout → "Complete test payment" → signed webhook →
| ActivateSubscription → `.entitlements.changed` → the thread re-renders.
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
});

it('M10 acceptance: after a test payment the chat composer unlocks live, without a reload', function (): void {
    $this->browse(function (Browser $chat, Browser $pay): void {
        $bride = memberWithPhone(DUSK_MEMBER_PHONE);
        $bride->profile->forceFill(['gender' => Gender::Female, 'status' => ProfileStatus::Active, 'published_at' => now()])->save();
        $groom = duskSecondMember();   // Free
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        app(AcceptInterest::class)->handle($bride->refresh(), $groom->profile);

        duskSignInSecond($chat, '/messages?with='.$bride->profile->code);
        $chat->waitForText('Upgrade to chat')
            ->assertMissing('#msgText')
            ->waitUsing(20, 100, fn (): bool => (bool) $chat->script(
                "return window.Echo?.connector?.channels?.['private-App.Models.User.{$groom->id}']?.subscription?.subscribed === true;")[0]);
        $chat->script("window.__samePage = true; window.__unlockedAt = null; new MutationObserver(() => {
            if (window.__unlockedAt === null && document.querySelector('#msgText')) window.__unlockedAt = Date.now();
        }).observe(document.body, { childList: true, subtree: true });");

        duskSignInSecond($pay, '/checkout/silver?duration=1');
        $pay->waitForText('Silver Membership')
            ->assertSee('Test mode — no real payment is taken.')
            ->assertSee('₹588.82')
            ->press('@checkout-pay')
            ->waitFor('@checkout-test-pay');

        $startedAt = (int) round(microtime(true) * 1000);
        $pay->press('@checkout-test-pay')
            ->waitForText('Payment received', 15)
            ->assertSee('Your Silver membership is active until')
            ->assertSee('Download invoice');

        $chat->waitUsing(10, 50, fn (): bool => (bool) $chat->script('return window.__unlockedAt !== null;')[0])
            ->assertPresent('#msgText')
            ->assertDontSee('Upgrade to chat');
        expect((bool) $chat->script('return window.__samePage === true;')[0])->toBeTrue();   // no reload

        $after = (int) $chat->script('return window.__unlockedAt;')[0] - $startedAt;
        fwrite(STDERR, "\n  checkout: composer unlocked {$after} ms after \"Complete test payment\"\n");
    });
});

it('oppam-ui-standards: /plans, checkout and the success page never scroll sideways at 320–1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        $groom = duskSecondMember();
        $browser->visit('/')->driver->manage()->deleteAllCookies();   // the browser may still hold the previous test's session
        duskSignInSecond($browser, '/plans?duration=12');
        $order = checkoutOrder($groom, App\Enums\PlanCode::Gold, 3);
        capturePayment($order);

        foreach (['/plans?duration=12', '/checkout/diamond?duration=6', '/billing/success/'.$order->code] as $path) {
            $browser->visit($path)->waitFor('main');
            foreach ([320, 375, 768, 1024, 1440] as $width) {
                $browser->resize($width, 900)->pause(150);   // let the layout settle after the resize
                expect((bool) $browser->script('return document.scrollingElement.scrollWidth <= document.scrollingElement.clientWidth;')[0])
                    ->toBeTrue("{$path} scrolls sideways at {$width}px");
            }
        }

        $browser->resize(375, 800)->visit('/billing/success/'.$order->code)
            ->waitForText('Payment received')
            ->assertVisible('a[href*="/billing/invoices/OPM-"]');
    });
});
