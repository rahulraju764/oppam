<?php

declare(strict_types=1);

use App\Models\EmailChangeRequest;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;

/*
| P8.2b — the Account tab (PRD M14 / M01) in a real browser at 375 px: change the mobile number
| through both codes (read from the local SMS log), confirm an email address from its link page,
| and the tab loaded at 320 / 768 / 1024 / 1440 px without sideways scrolling.
| Against the dev server and database: the Dusk member (under either number) is removed before and after.
*/

const DUSK_NEW_PHONE = '+919555500077';

function duskAccountCleanup(): void
{
    foreach ([DUSK_MEMBER_PHONE, DUSK_NEW_PHONE] as $phone) {
        $user = User::withTrashed()->where('phone', $phone)->first();
        if ($user !== null) {
            RateLimiter::clear('email-change:'.$user->id);
            RateLimiter::clear('password-change:'.$user->id);
        }
        duskMemberCleanup($phone);
    }
}

beforeEach(fn () => duskAccountCleanup());
afterEach(fn () => duskAccountCleanup());

/** Centre an element: a top-aligned one sits under the sticky mobile header. */
function duskAccountCentre(Browser $browser, string $selector): Browser
{
    $browser->script('document.querySelector('.json_encode($selector).').scrollIntoView({block: "center"});');

    return $browser;
}

it('M14 at 375 px: change the mobile number with a code to the old and to the new number', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        $member = duskLiveMember($browser);

        $browser->visit('/settings?tab=account')->waitFor('@phone-start', 15);
        assertNoHorizontalScroll($browser);
        RateLimiter::clear('otp:gap:'.sha1(DUSK_MEMBER_PHONE));   // registration texted this number seconds ago (30 s gap)

        duskAccountCentre($browser, '[dusk=phone-start]')->click('@phone-start')->waitFor('@phone-old-code', 10);
        $old = duskLatestOtp(PhoneNumber::fromE164(DUSK_MEMBER_PHONE)->masked());
        expect($old)->not->toBeNull();
        duskAccountCentre($browser, '[dusk=phone-old-code]')->type('@phone-old-code', (string) $old)->click('@phone-old-confirm')
            ->waitFor('@phone-new-number', 10);

        $national = substr(DUSK_NEW_PHONE, 3);
        duskAccountCentre($browser, '[dusk=phone-new-number]')->type('@phone-new-number', $national)->click('@phone-new-send')
            ->waitFor('@phone-new-code', 10);
        $new = duskLatestOtp(PhoneNumber::fromE164(DUSK_NEW_PHONE)->masked());
        expect($new)->not->toBeNull();
        $browser->script('window.__beforeChange = 1;');
        duskAccountCentre($browser, '[dusk=phone-new-code]')->type('@phone-new-code', (string) $new)->click('@phone-new-confirm');

        // The live `.session.revoked` (sent a few seconds after the response) reloads this tab too; it
        // must come back signed in with its NEW session id, showing the new number.
        $masked = PhoneNumber::fromE164(DUSK_NEW_PHONE)->masked();
        $browser->waitForText($masked, 15)
            ->waitUsing(20, 200, fn (): bool => $browser->script('return window.__beforeChange === undefined;')[0] === true)   // the page reloaded
            ->waitFor('@phone-start', 15)->assertPathIs('/settings')->assertSee($masked);   // lazy tab loaded again, signed in
        expect($member->refresh()->phone)->toBe(DUSK_NEW_PHONE);
        assertNoHorizontalScroll($browser);
    });
});

it('M14: an email confirmation link opens a page whose button confirms the address', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();

        // Signed out: Forgot password's "email me a link" mode fits a phone.
        $browser->visit('/forgot-password')->waitUntilMissing('#preloader', 10)->waitFor('@reset-by-email')
            ->click('@reset-by-email')->waitFor('@reset-email');
        assertNoHorizontalScroll($browser);

        $member = duskLiveMember($browser);
        $token = str_repeat('d', 64);
        EmailChangeRequest::factory()->forToken($token)->create(['user_id' => $member->id, 'email' => 'dusk-verify@example.test']);

        $browser->visit('/settings/email/verify/'.$token)->waitFor('@email-confirm', 10);
        assertNoHorizontalScroll($browser);
        expect($member->refresh()->email_verified_at)->toBeNull();   // opening alone changes nothing

        $browser->click('@email-confirm')->waitForText('your email address is confirmed', 10);
        expect($member->refresh()->email)->toBe('dusk-verify@example.test')->and($member->email_verified_at)->not->toBeNull();
    });
});

it('the Account tab fits 320 / 768 / 1024 / 1440 px, loaded', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskLiveMember($browser);

        assertFitsWidths($browser, url('/favicon.ico'), [url('/settings?tab=account')]);

        $browser->resize(320, 800)->visit('/settings?tab=account')->waitFor('@phone-start', 15);
        assertNoHorizontalScroll($browser);
    });
});
