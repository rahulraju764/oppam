<?php

declare(strict_types=1);

use App\Models\DailyMatch;
use App\Models\Profile;
use Laravel\Dusk\Browser;

/*
| P2.4 — Daily Matches and Visitors at phone width (375px): no sideways scroll; dismiss works.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.4: daily matches and visitors work at 375px', function (): void {
    $this->browse(function (Browser $browser): void {
        $member = duskLiveMember($browser);
        $groom = Profile::query()->where('status', 'ACTIVE')->where('gender', 'MALE')->orderBy('code')->firstOrFail();
        DailyMatch::factory()->create(['profile_id' => $member->profile->id, 'matched_profile_id' => $groom->id, 'score' => 90]);

        $browser->visit('/matches/daily')->waitForText('expire in')->assertSee($groom->code);
        assertNoHorizontalScroll($browser);
        $browser->click('button[aria-label^="Not interested in"]')->waitForText('Removed from today');

        $browser->visit('/visitors')->waitForText('Who viewed me');
        assertNoHorizontalScroll($browser);
    });
});
