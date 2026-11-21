<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.2b1 — A12 system health panel in a real browser (`composer dev` running: web, queue worker,
| Reverb, Vite): the lazy panel loads past its skeleton into every card, the System health link is
| in the sidebar, and the page fits 320 – 1440 px. Read-only: nothing is written.
*/

beforeEach(fn () => duskAdminCleanup());
afterEach(fn () => duskAdminCleanup());

it('P9.2b1: the system health panel loads every card from the sidebar and fits every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/'))
            ->clickLink('System health')
            ->waitForText('Scheduled tasks', 15)
            ->assertSee('Queues')
            ->assertSee('Live updates (Reverb)')
            ->assertSee('Webhooks (last 24 hours)')
            ->assertSee('Outbound email & SMS (last 24 hours)')
            ->assertSee('Database')
            ->assertSee('Open Horizon');

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            adminDuskUrl('/system/health'),
        ]);
    });
});
