<?php

declare(strict_types=1);

use App\Actions\Admin\Members\SuspendMember;
use Laravel\Dusk\Browser;

/*
| P3.1 / P1.7a — a suspension signs the member's open tab out live: ForceLogout (`.session.revoked`)
| arrives over Reverb, the tab reloads and the session middleware signs it out. Needs `composer dev`.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

it('P3.1: suspending a member sends their open tab to the sign-in page without a click', function (): void {
    $this->browse(function (Browser $browser): void {
        $member = duskLiveMember($browser);

        $browser->resize(1280, 800)
            ->visit('/me')
            // Subscribed to the member's own channel (after its /broadcasting/auth round trip), not just connected.
            ->waitUsing(15, 100, fn (): bool => (bool) $browser->script("return window.Alpine?.store('realtime')?.userChannel === true;")[0]);

        app(SuspendMember::class)->handle(duskAdmin(), $member, 'Dusk: live forced logout check.');

        $browser->waitForLocation('/login', 15);
    });
});
