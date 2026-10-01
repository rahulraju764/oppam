<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P1.1 — M01 at phone width (375 px device emulation): the home hero form end to end, OTP login,
| and no horizontal scroll on the auth pages. Against the dev server; throwaway number cleaned
| around each test (tests/Support/dusk-member.php).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

function assertNoHorizontalScroll(Browser $browser): void
{
    expect((bool) $browser->script('return document.scrollingElement.scrollWidth <= document.scrollingElement.clientWidth;')[0])->toBeTrue();
}

it('registers from the home hero on a phone and lands on the wizard', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')
            ->waitUntilMissing('#preloader', 10)
            ->scrollIntoView('#reg-created-for')
            ->select('#reg-created-for', 'SELF')
            ->type('#reg-first-name', 'Dusk')
            ->radio('gender', 'FEMALE')
            ->type('#reg-dob-day', '12')
            ->type('#reg-dob-month', '05')
            ->type('#reg-dob-year', '1998')
            ->type('#reg-mobile', '9999900001')
            ->type('#reg-password', 'kerala2026')
            ->check('#reg-terms')
            ->click('.regi-button button[type="submit"]')
            ->waitForLocation('/verify-otp');

        assertNoHorizontalScroll($browser);

        $browser->type('#otpCode', (string) duskLatestOtp('+91 99•••••001'))
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/onboarding/1')
            ->assertSourceHas('class="register-wrapper"');   // the profile wizard (P1.2)

        assertNoHorizontalScroll($browser);
    });
});

it('logs in with an SMS code on a phone', function (): void {
    // A verified member made through the real registration screens first.
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitUntilMissing('#preloader', 10)
            ->select('#profileFor', 'SON')
            ->waitForText('Set from')
            ->type('#regName', 'Dusk Groom')
            ->type('#regMobile', '9999900001')
            ->type('#regPassword', 'kerala2026')
            ->check('#regTerms')
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/verify-otp')
            ->type('#otpCode', (string) duskLatestOtp('+91 99•••••001'))
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/onboarding/1');

        $browser->driver->manage()->deleteAllCookies();
        Illuminate\Support\Facades\RateLimiter::clear('otp:gap:'.sha1(DUSK_MEMBER_PHONE));

        $browser->visit('/login')
            ->waitUntilMissing('#preloader', 10)
            ->click('.login-form .login-btn-alt')
            ->waitFor('#loginMobile')
            ->type('#loginMobile', '9999900001')
            ->click('.login-form button[type="submit"]')
            ->waitForText('If this number is registered')
            ->waitFor('#otpCode')
            ->type('#otpCode', (string) duskLatestOtp('+91 99•••••001'))
            ->click('.login-form button.login-btn[type="submit"]')
            ->waitForLocation('/onboarding/1');
    });
});

it('has no horizontal scroll on the auth pages at 375 px', function (string $path): void {
    $this->browse(function (Browser $browser) use ($path): void {
        $browser->visit($path)->waitUntilMissing('#preloader', 10);

        assertNoHorizontalScroll($browser);
    });
})->with(['/login', '/register', '/forgot-password']);

it('P1.2: the wizard has no horizontal scroll at 375 px', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitUntilMissing('#preloader', 10)
            ->select('#profileFor', 'SON')
            ->waitForText('Set from')
            ->type('#regName', 'Dusk Groom')
            ->type('#regMobile', '9999900001')
            ->type('#regPassword', 'kerala2026')
            ->check('#regTerms')
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/verify-otp')
            ->type('#otpCode', (string) duskLatestOtp('+91 99•••••001'))
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/onboarding/1')
            ->waitUntilMissing('#preloader', 10);

        assertNoHorizontalScroll($browser);
    });
});
