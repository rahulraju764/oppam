<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3b — WCAG 2.1 A / AA on the admin panel (axe-core; PRD §14), desktop, `composer dev` running; plus the
| widths not checked before: the A02 dashboard (P3.5) and every A13 chat screen (P4.3a / P4.3b), 320–1440 px.
*/

beforeEach(fn () => duskAdminCleanup());
afterEach(fn () => duskAdminCleanup());

it('a11y: the admin screens pass WCAG 2.1 A/AA; A02 and the A13 chat screens fit 320–1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        foreach (['/', '/members', '/moderation/profiles', '/chat/flags', '/chat/signals', '/chat/overview', '/chat/rules', '/chat/break-glass',
            '/billing/orders', '/brokers', '/content/home-blocks', '/reports', '/reports/growth', '/audit', '/system/health',
            '/settings/site', '/settings/rules', '/staff', '/roles'] as $page) {
            $browser->visit(adminDuskUrl($page))->waitFor('main#main', 15)->pause(400);
            assertAccessible($browser, 'admin '.$page);
        }

        // A dialog: the A13 rules editor (x-ui.modal), checked by axe while open (at this desktop width only — dialogs at
        // 320–1440 px and the flag-review / break-glass detail screens are an open P9.3b item: they need chat fixtures).
        $browser->visit(adminDuskUrl('/chat/rules'))->waitFor('main#main', 15)
            ->script("window.dispatchEvent(new CustomEvent('open-modal', { detail: { name: 'edit-rule' } }));");
        $browser->pause(400);
        assertAccessible($browser, 'admin /chat/rules dialog');

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), array_map(fn (string $path): string => adminDuskUrl($path), [
            '/', '/chat/flags', '/chat/signals', '/chat/overview', '/chat/rules', '/chat/break-glass',
        ]));
    });
});
