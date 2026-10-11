<?php

declare(strict_types=1);

use App\Enums\InterestStatus;
use App\Models\Conversation;
use App\Models\Interest;
use Laravel\Dusk\Browser;

/*
| P3.4 — F02 end to end in two real browsers over Reverb (needs `composer dev`):
| like → mutual ("It's a match!") → Send interest from the dialog → the other member's button
| switches to "Respond" live → Accept on /interests → the Chat button becomes active on BOTH sides
| without a reload (M06 acceptance). Uses the second-member helper from PresenceTest.
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    // The accepted pair's conversation has no cascade from profiles: remove it before the members.
    $ids = App\Models\Profile::withTrashed()->whereIn('user_id', App\Models\User::withTrashed()
        ->whereIn('phone', [DUSK_MEMBER_PHONE, DUSK_SECOND_PHONE])->select('id'))->pluck('id');
    Conversation::query()->whereIn('id', App\Models\ConversationParticipant::query()->whereIn('profile_id', $ids)->select('conversation_id'))->delete();

    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

it('F02: like → mutual → interest → accept → the Chat button is active on both sides, live', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        $bride = duskLiveMember($first);   // the Dusk bride, signed in (Free plan)
        $groom = duskSecondMember();
        $userChannelReady = "return window.Alpine?.store('realtime')?.userChannel === true;";
        $shown = fn (Browser $b, string $selector): bool => (bool) $b->script(
            "const el = document.querySelector('{$selector}'); return el !== null && getComputedStyle(el).display !== 'none';")[0];

        $second->resize(1280, 800)->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->visit('/profile/'.$bride->profile->code)
            ->waitFor('.profile-actions .like-btn')
            ->waitUsing(15, 100, fn (): bool => (bool) $second->script($userChannelReady)[0])
            ->click('.profile-actions .like-btn')
            ->waitFor('.profile-actions .like-btn[aria-pressed="true"]', 10);

        // She likes him back: "It's a match!" → Send interest → the dialog → Send.
        $first->resize(1280, 800)->visit('/profile/'.$groom->profile->code)
            ->waitFor('.profile-actions .like-btn')
            ->waitUsing(15, 100, fn (): bool => (bool) $first->script($userChannelReady)[0])
            ->click('.profile-actions .like-btn')
            ->waitForText('It’s a match!', 10)
            ->press('Send interest')
            ->waitForText('interests left this month', 10)
            ->assertSee('I liked your profile and would like to know more about you.')
            ->click('button[form="send-interest-form"]')
            ->waitUsing(10, 200, fn (): bool => $shown($first, '.profile-actions .interest-chip'))
            ->waitUsing(10, 200, fn (): bool => Interest::query()->where('from_profile_id', $bride->profile->id)->where('to_profile_id', $groom->profile->id)
                ->where('status', InterestStatus::Pending->value)->exists());

        // His open page learns of it live: "Respond to interest", no reload.
        $second->waitUsing(20, 200, fn (): bool => $shown($second, '.profile-actions a.interest-btn'))
            ->visit('/interests')
            ->waitForText('reply before they expire')
            ->click('.btn-accept')
            ->waitForText('You are now connected', 10);

        // M06 acceptance: Chat is active on both sides — hers without touching the page.
        $first->waitUsing(20, 200, fn (): bool => $shown($first, '.profile-actions .chat-btn'))
            ->assertEnabled('.profile-actions .chat-btn');

        $second->visit('/profile/'.$bride->profile->code)
            ->waitFor('.profile-actions .chat-btn')
            ->waitUsing(10, 200, fn (): bool => $shown($second, '.profile-actions .chat-btn'))
            ->assertEnabled('.profile-actions .chat-btn')
            ->assertSee('Dusk Bride');   // connected: the full name

        expect(Conversation::query()->where('pair_key', Conversation::pairKey($bride->profile->id, $groom->profile->id))->exists())->toBeTrue();
    });
});
