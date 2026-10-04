<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P2.1 review — the search filter rail on a desktop width: with "More filters" open the card is
| taller than the screen; it scrolls inside its sticky box, so its last control ("Reset filters")
| can always be reached.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.1: with More filters open, the end of the filter rail is reachable on a laptop screen', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);

        $browser->resize(1280, 720)
            ->visit('/search')
            ->waitForText('Search Results')
            ->click('.more-filters-toggle')
            ->waitForText('Show only')
            ->script("document.querySelector('.filter-reset').scrollIntoView({block: 'center'});");

        $browser->assertScript("getComputedStyle(document.querySelector('.search-sheet')).overflowY === 'auto'")   // scrolls inside, capped to the screen
            ->assertScript("document.querySelector('.search-sheet').getBoundingClientRect().height <= window.innerHeight")
            ->assertVisible('.filter-reset')
            ->assertScript("(() => { const r = document.querySelector('.filter-reset').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; })()")
            ->click('.filter-reset')
            ->waitForText('Search Results');
    });
});
