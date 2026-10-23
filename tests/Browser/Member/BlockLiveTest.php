<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use App\Models\Block;
use Laravel\Dusk\Browser;

/*
| P6.2 "Done when: block effects visible live on both sides" — real browsers over Reverb (needs
| `composer dev`). The bride blocks the groom from his profile page (More → Block → confirm): her
| page moves to Blocked & ignored; the groom's open conversation with her leaves for his inbox
| without a reload. Nothing on his screen says he was blocked.
*/

beforeEach(fn () => duskChatCleanup());
afterEach(fn () => duskChatCleanup());

it('M09 / R-M06-4: a block takes effect live on both sides', function (): void {
    $this->browse(function (Browser $bride, Browser $groom): void {
        $brideUser = duskLiveMember($bride);
        $groomUser = chatOnPlan(duskSecondMember(), PlanCode::Gold);
        app(SendInterest::class)->handle($groomUser, $brideUser->profile, 'T01');
        $thread = app(AcceptInterest::class)->handle($brideUser, $groomUser->profile);

        duskSignInSecond($groom, '/messages?with='.$brideUser->profile->code);
        $groom->resize(1280, 900)->waitFor('#msgText');
        duskChatSubscribed($groom, $thread);
        $groom->waitUsing(20, 100, fn (): bool => (bool) $groom->script(
            "return window.Echo?.connector?.channels?.['private-App.Models.User.{$groomUser->id}']?.subscription?.subscribed === true;")[0]);

        $bride->resize(1280, 900)->visit('/profile/'.$groomUser->profile->code)
            ->waitFor('.safety-menu__toggle')
            ->click('.safety-menu__toggle')
            ->waitFor('.safety-menu__item--danger')
            ->click('.safety-menu__item--danger')
            ->waitFor('@block-confirm')
            ->press('@block-confirm')
            ->waitForLocation('/settings/blocked', 10)
            ->assertSee('You blocked');

        expect(Block::query()->where('blocker_profile_id', $brideUser->profile->id)->exists())->toBeTrue();

        // The groom's open thread leaves for his inbox, live.
        $groom->waitUsing(10, 100, fn (): bool => ! str_contains((string) $groom->driver->getCurrentURL(), 'with='))
            ->assertPathIs('/messages')
            ->assertDontSee('blocked');
    });
});
