<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;
use PragmaRX\Google2FA\Google2FA;

/*
| P0.5 — the real admin sign-in journey in a browser, against the dev server (`composer dev`):
| admin domain, own session cookie, password → forced 2FA enrolment → recovery codes → panel →
| a Livewire action on an admin page (persistent middleware) → sign out.
| Uses a throwaway local account (dusk-admin@oppam.test) created and deleted around each test.
| Note: audit rows it writes stay in the local database (audit_logs is append-only).
*/

// The throwaway Dusk admin never outlives its test (tests/Support/dusk.php).
afterEach(fn () => duskAdminCleanup());

it('signs a new admin in through password and forced 2FA enrolment, then out again', function (): void {
    duskAdmin();

    $this->browse(function (Browser $browser): void {
        $browser->visit(adminDuskUrl('/'))
            ->waitForLocation('/login')
            ->assertSee('Admin sign in')
            ->type('#f-email', DUSK_ADMIN_EMAIL)
            ->type('#f-password', duskAdminPassword())
            ->press('Continue')
            ->waitForLocation('/two-factor/setup')
            ->assertSee('Set up two-factor authentication');

        $key = str_replace(' ', '', $browser->text('.mono-key'));
        $code = app(Google2FA::class)->getCurrentOtp($key);

        $browser->type('#f-code', $code)
            ->press('Confirm')
            ->waitForText('Save your recovery codes')
            ->assertPresent('.recovery-codes li:nth-child(10)')
            ->press('I have saved them — continue')
            ->waitForLocation('/')
            ->assertSee('Welcome, Dusk Admin')
            ->assertSee('Admin users');

        // The admin session cookie is separate from the member site's.
        expect($browser->driver->manage()->getCookieNamed(config('oppam.admin.session_cookie')))->not->toBeNull();

        // A Livewire request on an admin page passes the persistent admin middleware.
        $browser->visit(adminDuskUrl('/staff'))
            ->waitForText('Admin users')
            ->type('#f-search', 'dusk')
            ->waitUntilMissingText('Demo Moderator')
            ->assertSee(DUSK_ADMIN_EMAIL);

        $browser->press('Sign out')
            ->waitForLocation('/login')
            ->visit(adminDuskUrl('/staff'))
            ->waitForLocation('/login');
    });
});
