<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P2.3 — dashboard and My Matches on a laptop screen: the lazy sliders replace their skeletons and
| start as Swiper carousels; the funnel counters switch the tab (keyboard included).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P2.3: dashboard sliders load lazily and start; My Matches counters switch tabs', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);

        $browser->resize(1280, 800)
            ->visit('/dashboard')
            ->waitForText('New Matches');
        duskLoadAllStrips($browser);   // #[Lazy]: a strip loads when it scrolls into view
        $browser->waitUsing(10, 100, fn (): bool => (bool) $browser->script("return document.querySelector('.dashboard-strip .match-slider.swiper-initialized') !== null;")[0]);

        // The third funnel counter ("Viewed") is a real button: Enter on it switches the tab.
        $browser->visit('/matches')
            ->waitForTextIn('.content-title h1', 'All Matches')
            ->keys('.counter-section .row > div:nth-child(3) .counter-button', '{enter}')
            ->waitUsing(10, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), 'tab=viewed'))
            ->assertSeeIn('.content-title h1', 'Viewed')
            ->assertAttribute('.counter-section .row > div:nth-child(3) .counter-button', 'aria-pressed', 'true');
    });
});
