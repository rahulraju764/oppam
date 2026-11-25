<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3b (P8.2b hand-over) — "Sign out all other devices" over the real Reverb server, two browsers signed in
| as the SAME member (`composer dev` running): the other device is sent to /login live (session.revoked on
| the member's private channel → reload → the session epoch ends it), while the device that asked stays in.
| Test only — no session code changes (CLAUDE.md rule 5 lists sessions: owner to note).
*/

beforeEach(function (): void {
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

it('P8.2b: signing out other devices sends the other browser to /login live; this one stays signed in', function (): void {
    $this->browse(function (Browser $asking, Browser $other): void {
        duskSecondMember();

        duskSignInSecond($other, '/dashboard');
        $other->waitUsing(15, 200, fn (): bool => (bool) $other->script("return window.Alpine?.store('realtime')?.userChannel === true;")[0]);

        duskSignInSecond($asking, '/settings?tab=account');
        $asking->waitFor('@devices-signout')->click('@devices-signout')->waitForText('Every other device was signed out.', 10);

        $other->waitForLocation('/login', 15);
        $asking->visit('/dashboard')->assertPathIs('/dashboard');
    });
});
