<?php

declare(strict_types=1);

use App\Models\Profile;
use Laravel\Dusk\Browser;

/*
| P9.2a — A12 audit log in a real browser (`composer dev` running): filter by an area, open a row's
| drawer and see the before / after; a member's Timeline tab links into the viewer filtered to that
| member; the viewer fits 320 – 1440 px. Read-only: nothing is written (the sign-in writes its own
| audit rows, which is what the viewer then lists).
*/

beforeEach(fn () => duskAdminCleanup());
afterEach(fn () => duskAdminCleanup());

it('P9.2a: filter the audit log, open an entry, follow a member timeline into the viewer; fits every width', function (): void {
    $code = Profile::query()->whereNotNull('code')->value('code');

    $this->browse(function (Browser $browser) use ($code): void {
        duskSignInAdmin($browser);   // writes admin.login — at least one row to list

        $browser->visit(adminDuskUrl('/audit'))
            ->waitForText('Audit log')
            ->type('#audit-action', 'admin')
            ->waitForText('admin.')
            ->click('table.admin-table tbody tr:first-child button')
            ->waitForText('Before and after')
            ->assertSee('IP address')
            ->keys('', '{escape}');

        if ($code !== null) {
            $browser->visit(adminDuskUrl('/members/'.$code.'?tab=timeline'))
                ->waitForText('Open in the audit log')
                ->clickLink('Open in the audit log')
                ->waitForText('Timeline of '.$code)
                ->assertQueryStringHas('entity', 'member:'.$code);
        }

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            adminDuskUrl('/audit'),
            adminDuskUrl('/audit?action=admin'),
        ]);
    });
});
