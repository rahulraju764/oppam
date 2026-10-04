<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P2.3 — dashboard and /matches at phone width (375px): no sideways scroll; the match categories
| open in a bottom sheet whose tab buttons work (they stay inside the Livewire component).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.3: dashboard and My Matches work at 375px', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);

        $browser->visit('/dashboard')->waitForText('Daily Recommendations');
        duskLoadAllStrips($browser);
        assertNoHorizontalScroll($browser);

        $browser->visit('/matches')
            ->waitForText('Your match funnel')
            ->click('#open-matches-rail')
            ->waitFor('#matches-rail.is-open')
            ->waitForTextIn('#matches-rail', 'Premium')
            ->click('#matches-rail button[wire\\:click="show(\'premium\')"]')
            ->waitUntilMissing('#matches-rail.is-open')
            ->waitUsing(10, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), 'tab=premium'))
            ->assertSeeIn('.content-title h1', 'Premium');
        assertNoHorizontalScroll($browser);
    });
});
