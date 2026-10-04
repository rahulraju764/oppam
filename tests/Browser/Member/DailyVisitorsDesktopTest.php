<?php

declare(strict_types=1);

use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\ProfileView;
use Laravel\Dusk\Browser;

/*
| P2.4 — Daily Matches and Visitors on a laptop screen: "Not interested" removes a match without a
| reload; the visitors tabs switch (Free member: count + upgrade, no names).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.4: dismiss a daily match; visitors tabs switch and Free sees no visitor names', function (): void {
    $this->browse(function (Browser $browser): void {
        $member = duskLiveMember($browser);   // a bride
        $groom = Profile::query()->where('status', 'ACTIVE')->where('gender', 'MALE')->orderBy('code')->firstOrFail();
        DailyMatch::factory()->create(['profile_id' => $member->profile->id, 'matched_profile_id' => $groom->id, 'score' => 90]);
        ProfileView::factory()->create(['viewer_profile_id' => $groom->id, 'viewed_profile_id' => $member->profile->id]);

        $browser->resize(1280, 800)
            ->visit('/matches/daily')
            ->waitForText('expire in')
            ->assertSee($groom->code)
            ->click('button[aria-label^="Not interested in"]')
            ->waitForText('Removed from today')
            ->assertDontSee($groom->code);

        $browser->visit('/visitors')
            ->waitForText('Who viewed me')
            ->assertSee('1 member viewed your profile')
            ->assertDontSee($groom->code)
            ->press('Profiles I viewed')
            ->waitUsing(10, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), 'tab=viewed'))
            ->assertAttribute('.visitor-tab:nth-child(2)', 'aria-pressed', 'true');
    });
});
