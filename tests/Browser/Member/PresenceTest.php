<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Dusk\Browser;

/*
| P3.1 — presence over the real Reverb server (needs `composer dev` running): with two browsers,
| the first member watches the second member's profile, and the green online dot appears as soon
| as the second member signs in (presence.online, $store.online — no reload).
*/

const DUSK_SECOND_PHONE = '+919999900002';

beforeEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

/** A live groom the Dusk bride can see, signing in with email + password. */
function duskSecondMember(): User
{
    $groom = memberWithPhone(DUSK_SECOND_PHONE);
    $groom->forceFill(['email' => 'dusk.groom@example.com', 'email_verified_at' => now()])->save();
    $groom->profile->forceFill(['gender' => App\Enums\Gender::Male, 'status' => App\Enums\ProfileStatus::Active, 'published_at' => now()])->save();

    return $groom->refresh();
}

it('P3.1: the online dot turns green when a second member signs in', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        duskLiveMember($first);   // the Dusk bride, signed in
        $groom = duskSecondMember();
        $dot = '[data-online-code="'.$groom->profile->code.'"]';

        $first->resize(1280, 800)
            ->visit('/profile/'.$groom->profile->code)
            ->waitForText($groom->profile->code)
            ->waitUsing(10, 100, fn (): bool => (bool) $first->script("return window.Alpine?.store('realtime')?.status === 'connected';")[0])
            ->assertMissing($dot.':not([style*="display: none"])');

        $second->resize(1280, 800)
            ->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard');

        $first->waitUsing(15, 200, fn (): bool => (bool) $first->script("const d = document.querySelector('{$dot}'); return d !== null && getComputedStyle(d).display !== 'none';")[0]);
    });
});
