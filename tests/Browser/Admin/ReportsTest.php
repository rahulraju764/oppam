<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.1a — A09 report screens in a real browser (`composer dev` running): change the period and
| the chart is drawn again for the new range; every report and the catalogue fit 320 – 1440 px.
| Read-only: nothing is written.
*/

beforeEach(fn () => duskAdminCleanup());
afterEach(fn () => duskAdminCleanup());

it('P9.1a: changing the period redraws the report; the report screens fit every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/reports'))
            ->waitForText('Reports')
            ->click('@report-growth')
            ->waitForText('Signups by source')
            ->select('#report-preset', '7d')
            ->waitForText('Last 7 days')
            ->assertPresent('canvas');

        $from = $browser->script("return document.querySelector('[wire\\\\:key^=\"chart-growth-daily-\"]').getAttribute('wire:key');")[0];
        $browser->select('#report-preset', '90d')->pause(800);
        $after = $browser->script("return document.querySelector('[wire\\\\:key^=\"chart-growth-daily-\"]').getAttribute('wire:key');")[0];
        expect($after)->not->toBe($from);   // a new canvas for the new period

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            adminDuskUrl('/reports'),
            adminDuskUrl('/reports/growth'),
            adminDuskUrl('/reports/engagement'),
            adminDuskUrl('/reports/marketplace'),
            adminDuskUrl('/reports/exports'),
        ]);
    });
});
