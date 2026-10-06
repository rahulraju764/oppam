<?php

declare(strict_types=1);

use App\Models\Like;
use Laravel\Dusk\Browser;

/*
| P3.3 — M06 acceptance in real browsers over Reverb (needs `composer dev`): with two members,
| a like raises the liked member's bell and updates her open /likes page without a refresh; her
| like back shows "It's a match!" to her at once and reaches him as a notification.
| Uses the second-member helper from PresenceTest.
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

it('P3.3: a like updates the other member\'s bell and Likes page live; liking back shows "It\'s a match!"', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        $bride = duskLiveMember($first);   // the Dusk bride, signed in (Free plan)
        $groom = duskSecondMember();
        $userChannelReady = "return window.Alpine?.store('realtime')?.userChannel === true;";
        $bell = fn (Browser $b): string => (string) $b->script("return document.querySelector('.nav-bell .nav-bell-count')?.textContent?.trim() ?? '0';")[0];

        $first->resize(1280, 800)->visit('/likes')
            ->waitForText('No likes yet')
            ->waitUsing(15, 100, fn (): bool => (bool) $first->script($userChannelReady)[0]);

        $second->resize(1280, 800)->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->visit('/profile/'.$bride->profile->code)
            ->waitFor('.profile-actions .like-btn')
            ->waitUsing(15, 100, fn (): bool => (bool) $second->script($userChannelReady)[0])
            ->click('.profile-actions .like-btn')
            ->waitFor('.profile-actions .like-btn[aria-pressed="true"]', 10)
            ->waitUsing(10, 200, fn (): bool => Like::query()->where('from_profile_id', $groom->profile->id)->where('to_profile_id', $bride->profile->id)->exists());

        // M06 acceptance: her bell and her "Liked me" update in < 2 s without a refresh (Free: the count).
        $first->waitForText('1 member liked your profile', 20)
            ->waitUsing(20, 200, fn (): bool => $bell($first) === '1');

        // She likes him back from his profile: "It's a match!" at once.
        $first->visit('/profile/'.$groom->profile->code)
            ->waitFor('.profile-actions .like-btn')
            ->click('.profile-actions .like-btn')
            ->waitForText('It’s a match!', 10)
            ->assertSee('You like each other')
            ->press('Keep browsing')
            ->waitUntilMissingText('You like each other')
            ->assertPresent('.profile-actions .like-btn[aria-pressed="true"]');

        // He hears about the match live.
        $second->waitUsing(20, 200, fn (): bool => $bell($second) === '1');
    });
});
