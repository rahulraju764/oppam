<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P2.1 — /search in a real browser at phone width (375px): the filters open in a bottom sheet and
| close with "Show N profiles", a filter narrows the results and lands in the URL, nothing scrolls
| sideways. Uses the throwaway Dusk member (tests/Support/dusk-member.php).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.1: filters in a bottom sheet, results update, the URL keeps the filter', function (): void {
    $this->browse(function (Browser $browser): void {
        $member = duskLiveMember($browser);   // signed in, onboarded, ACTIVE

        $browser->visit('/search')
            ->waitForText('Search Results')
            ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth')
            ->assertMissing('.search-sheet.is-open');
        $countBefore = $browser->text('.search-results__head p');

        $browser->click('#open-filters')
            ->waitFor('.search-sheet.is-open')
            ->select('#age-min', '40')
            ->waitUsing(10, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), 'age_min'))
            ->waitUsing(10, 100, fn (): bool => $browser->text('.search-results__head p') !== $countBefore)   // the results narrowed
            ->click('.search-sheet__foot button')
            ->waitUntilMissing('.search-sheet.is-open')
            ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth');

        expect($member->profile()->exists())->toBeTrue();
    });
});
