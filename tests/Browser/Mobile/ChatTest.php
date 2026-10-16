<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use App\Models\Conversation;
use Laravel\Dusk\Browser;

/*
| P4.1 — chat at phone width (375 px emulation): one pane at a time (template TODO) — the list
| until a thread is chosen, then the thread with a back arrow; no sideways scrolling.
*/

beforeEach(function (): void {
    duskChatPhoneCleanup();
});

afterEach(function (): void {
    duskChatPhoneCleanup();
});

function duskChatPhoneCleanup(): void
{
    $ids = App\Models\Profile::withTrashed()->whereIn('user_id', App\Models\User::withTrashed()
        ->whereIn('phone', [DUSK_MEMBER_PHONE, '+919999900003'])->select('id'))->pluck('id');
    Conversation::query()->whereIn('id', App\Models\ConversationParticipant::query()->whereIn('profile_id', $ids)->select('conversation_id'))->delete();
    App\Models\Subscription::query()->whereIn('profile_id', $ids)->delete();

    duskMemberCleanup();
    duskMemberCleanup('+919999900003');
}

it('P4.1: /messages shows one pane at a time at 375px and never scrolls sideways', function (): void {
    $this->browse(function (Browser $browser): void {
        $bride = duskLiveMember($browser);
        $groom = memberWithPhone('+919999900003');
        $groom->profile->forceFill(['gender' => App\Enums\Gender::Male, 'first_name' => 'Phone', 'status' => App\Enums\ProfileStatus::Active, 'published_at' => now()])->save();
        chatOnPlan($groom->refresh(), PlanCode::Gold);
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        app(AcceptInterest::class)->handle($bride, $groom->profile);
        chatSend($groom, $bride, 'A message for the phone');

        $browser->visit('/messages')
            ->waitFor('.msg-thread')
            ->assertSee('A message for the phone')
            ->assertMissing('.msg-panel--empty');   // the empty thread pane is hidden on phones
        assertNoHorizontalScroll($browser);

        $browser->click('.msg-thread')
            ->waitFor('[data-chat-thread]')
            ->assertVisible('.msg-back')
            ->assertMissing('.msg-list')
            ->assertVisible('#msgText')   // he wrote, so the Free bride may reply once
            ->assertSee('You have 1 free reply left in this conversation.');
        assertNoHorizontalScroll($browser);

        $browser->click('.msg-back')->waitFor('.msg-list')->assertMissing('[data-chat-thread]');
    });
});
