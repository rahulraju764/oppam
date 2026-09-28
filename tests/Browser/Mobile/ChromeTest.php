<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P0.2 — site chrome at phone width (375px), against the local-only /styleguide pages.
| Needs `composer dev` (or `php artisan serve`) running on APP_URL. docs/template-notes.md
| describes the drawer and preloader rules these prove.
*/

function focusIsInsideDrawer(Browser $browser): bool
{
    return (bool) $browser->script('return document.getElementById("navbarSupportedContent").contains(document.activeElement);')[0];
}

it('lifts the preloader after the page loads and removes it from the DOM', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/styleguide/member')
            ->waitUntilMissing('#preloader', 10)
            ->assertMissing('#preloader');
    });
});

it('opens the mobile drawer as a modal dialog, traps focus and closes on Escape', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/styleguide/member')
            ->waitUntilMissing('#preloader', 10)
            ->assertScript('document.getElementById("navbarSupportedContent").parentNode === document.body')   // portalled
            ->click('.custom-toggler')
            ->waitFor('#navbarSupportedContent.open')
            ->assertAttribute('#navbarSupportedContent', 'role', 'dialog')
            ->assertAttribute('#navbarSupportedContent', 'aria-modal', 'true')
            ->assertAriaAttribute('.custom-toggler', 'expanded', 'true')
            ->waitUsing(3, 50, fn (): bool => focusIsInsideDrawer($browser));

        // Tab from the last focusable element wraps to the first (focus trap).
        $browser->script('const items=[...document.querySelectorAll("#navbarSupportedContent a[href], #navbarSupportedContent button:not([disabled])")].filter(e=>e.offsetParent!==null); items[items.length-1].focus();');
        $browser->driver->getKeyboard()->sendKeys(Facebook\WebDriver\WebDriverKeys::TAB);
        $wrappedToFirst = $browser->script('const items=[...document.querySelectorAll("#navbarSupportedContent a[href], #navbarSupportedContent button:not([disabled])")].filter(e=>e.offsetParent!==null); return document.activeElement === items[0];')[0];
        expect(focusIsInsideDrawer($browser))->toBeTrue()
            ->and($wrappedToFirst)->toBeTrue();

        $browser->driver->getKeyboard()->sendKeys(Facebook\WebDriver\WebDriverKeys::ESCAPE);
        $browser->waitUntilMissing('#navbarSupportedContent.open')
            ->assertMissing('#navbarSupportedContent[role="dialog"]')
            ->assertFocused('.custom-toggler');
    });
});

it('keeps one working drawer and no preloader across wire:navigate page changes', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/styleguide')
            ->waitUntilMissing('#preloader', 10)
            ->script('window.__sameDocument = true;');

        $browser->clickLink('/styleguide/member')
            ->waitForLocation('/styleguide/member')
            ->assertScript('window.__sameDocument === true')   // SPA navigation, not a reload
            ->assertMissing('#preloader')
            ->click('.custom-toggler')
            ->waitFor('#navbarSupportedContent.open')           // bound exactly once: one click opens it
            ->assertScript('document.querySelectorAll("#navbarSupportedContent").length === 1');
    });
});

it('has no horizontal overflow at phone width', function (string $path): void {
    $this->browse(function (Browser $browser) use ($path): void {
        $browser->visit($path)
            ->waitUntilMissing('#preloader', 10)
            ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth');
    });
})->with(['/styleguide', '/styleguide/member', '/', '/about', '/branches', '/success-stories', '/plans', '/contact', '/privacy', '/terms', '/no-such-page']);

it('stacks the home register form under the hero banner on a phone', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')
            ->waitUntilMissing('#preloader', 10)
            ->assertScript('document.querySelector(".register-form").getBoundingClientRect().top >= document.querySelector(".basement-carousel").getBoundingClientRect().bottom - 1')
            ->assertScript('document.querySelector(".basement-carousel").swiper !== undefined');   // carousel initialised
    });
});
