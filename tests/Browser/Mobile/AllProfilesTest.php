<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P2.2 — /profiles at phone width (375px): the browse menu and saved searches open in a bottom
| sheet (inside the Livewire component, so its buttons keep working), sorting updates the list,
| nothing scrolls sideways.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.2: browse sheet opens and closes, sort works, no sideways scroll', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);

        $browser->visit('/profiles')
            ->waitForText('All Profiles')
            ->assertMissing('#browse-rail.is-open');
        assertNoHorizontalScroll($browser);

        $browser->click('#open-browse')
            ->waitFor('#browse-rail.is-open')
            ->waitForTextIn('#browse-rail', 'No saved searches yet')   // after the slide-in
            ->click('#browse-rail .search-sheet__close')
            ->waitUntilMissing('#browse-rail.is-open')
            ->select('#sort', 'newest')
            ->waitUsing(10, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), 'sort=newest'));
        assertNoHorizontalScroll($browser);
    });
});
