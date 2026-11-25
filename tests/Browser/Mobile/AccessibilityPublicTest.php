<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3b — WCAG 2.1 A / AA on the public pages a guest sees, at phone width (axe-core; PRD §14). Needs
| `composer dev` running. Every violation fails; see assertAccessible() in tests/Support/dusk.php.
*/

it('a11y: the public pages pass WCAG 2.1 A/AA at phone width', function (string $path): void {
    $this->browse(function (Browser $browser) use ($path): void {
        $browser->visit($path)->waitUntilMissing('#preloader', 10)->waitFor('main#main');

        assertAccessible($browser, $path);
    });
})->with(['/', '/about', '/plans', '/success-stories', '/branches', '/contact', '/safety', '/terms', '/privacy', '/grievance', '/login', '/register', '/forgot-password']);

it('a11y: the admin sign-in page passes WCAG 2.1 A/AA and its skip link reaches the main landmark', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit(adminDuskUrl('/login'))->waitFor('main#main');

        assertAccessible($browser, 'admin /login');

        // The email field is autofocused, so a Tab from the page would continue from it (review M1): check instead
        // that the skip link is the FIRST tabbable element, then follow it with the keyboard.
        $first = $browser->script(<<<'JS'
            const tabbable = [...document.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])')]
                .filter(e => e.getAttribute('tabindex') !== '-1');
            return tabbable[0]?.classList.contains('skip-link') ?? false;
        JS)[0];
        expect($first)->toBeTrue('the skip link must be the first tabbable element');

        $browser->script('document.querySelector(".skip-link").focus();');
        $browser->keys('.skip-link', '{enter}');
        expect($browser->script('return location.hash;')[0])->toBe('#main');
    });
});
