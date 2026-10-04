<?php

declare(strict_types=1);

use App\Models\SavedSearch;
use Laravel\Dusk\Browser;

/*
| P2.2 — saved searches in a real browser (desktop): save from /search in the dialog (focus moves
| in and comes back), manage it on /profiles (rename dialog, alert select, delete with confirm).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.2: save a search in the dialog, then rename, change alerts and delete it on /profiles', function (): void {
    $this->browse(function (Browser $browser): void {
        $member = duskLiveMember($browser);

        $browser->resize(1280, 800)
            ->visit('/search?f[age_min]=25')
            ->waitForText('Search Results')
            ->press('Save search')
            ->waitFor('#save-name')
            ->assertFocused('#save-name')   // x-trap moved focus into the dialog
            ->clear('#save-name')->type('#save-name', 'Dusk brides')
            ->select('#save-frequency', 'WEEKLY')
            ->click('button[form="save-search-form"]')
            ->waitForText('Search saved.')
            ->waitUntilMissing('#save-name');

        $saved = SavedSearch::query()->where('profile_id', $member->profile->id)->sole();
        expect($saved->filters)->toBe(['age_min' => 25]);

        $browser->visit('/profiles')
            ->waitForText('All Profiles')
            ->assertSee('Dusk brides')
            ->assertSelected('#alerts-0', 'WEEKLY')
            ->select('#alerts-0', 'OFF')
            ->waitForText('Alert setting saved.')
            ->click('button[aria-label="Rename Dusk brides"]')
            ->waitFor('#rename-to')
            ->clear('#rename-to')->type('#rename-to', 'Dusk brides 25+')
            ->click('button[form="rename-search-form"]')
            ->waitForText('Saved search renamed.')
            ->assertSee('Dusk brides 25+')
            ->click('button[aria-label="Delete Dusk brides 25+"]')
            ->acceptDialog()
            ->waitForText('Saved search deleted.')
            ->assertSee('No saved searches yet');

        expect(SavedSearch::query()->where('profile_id', $member->profile->id)->exists())->toBeFalse();
    });
});
