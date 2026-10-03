<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P0.5 — the admin panel at phone width (375px, device emulation): the sidebar is an off-canvas
| menu opened from the top bar; no page scrolls sideways (admin tables scroll inside their card).
*/

// The throwaway Dusk admin never outlives its test (tests/Support/dusk.php).
afterEach(fn () => duskAdminCleanup());

it('opens the admin menu from the top bar and never scrolls sideways at 375px', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth')
            ->assertMissing('#admin-sidebar.is-open')
            ->click('.admin-menu-toggle')
            ->waitFor('#admin-sidebar.is-open')
            ->assertSeeIn('#admin-sidebar', 'Admin users');

        $browser->driver->getKeyboard()->sendKeys(Facebook\WebDriver\WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('#admin-sidebar.is-open');

        foreach (['/staff', '/roles', '/sessions', '/members', '/moderation/profiles', '/moderation/photos', '/moderation/edits', '/moderation/escalations'] as $path) {
            $browser->visit(adminDuskUrl($path))
                ->waitFor('.admin-page-head')
                ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth');
        }
    });
});
