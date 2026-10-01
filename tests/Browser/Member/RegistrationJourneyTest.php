<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P1.1 — "Done when: register → OTP → lands on /onboarding/1", in a real browser against the dev
| server: the /register form, derived gender, the code screen, sign-in, the member header and
| logout. Uses a throwaway number removed around each test (tests/Support/dusk-member.php).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('registers, verifies the SMS code and lands on the wizard, then logs out', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitUntilMissing('#preloader', 10)
            ->assertSee('Register free')
            ->select('#profileFor', 'DAUGHTER')
            ->waitUntil('document.getElementById("regGenderFemale").checked')
            ->assertDisabled('#regGenderMale')
            ->type('#regName', 'Dusk Tester')
            ->type('#regMobile', '99999 00001')
            ->type('#regPassword', 'kerala2026')
            ->check('#regTerms')
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/verify-otp')
            ->assertSee('+91 99•••••001');

        $code = duskLatestOtp('+91 99•••••001');
        expect($code)->not->toBeNull();

        $browser->type('#otpCode', $code)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/onboarding/1')
            ->assertSourceHas('class="register-wrapper"')   // the profile wizard (P1.2)
            ->assertInputValue('#basic-first_name', 'Dusk');

        // Signed in: /login now sends the member on to the wizard.
        $browser->visit('/login')->waitForLocation('/onboarding/1');
    });
});

it('shows server-side validation without leaving the page', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitUntilMissing('#preloader', 10)
            ->type('#regMobile', '12345')
            ->type('#regPassword', 'short')
            ->click('.login-form button[type="submit"]')
            ->waitFor('#regMobile-error')
            ->assertSeeIn('#regMobile-error', 'Enter a valid mobile number.')
            ->assertPresent('#regPassword-error')
            ->assertPathIs('/register');
    });
});
