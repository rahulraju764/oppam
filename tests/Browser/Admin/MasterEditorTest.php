<?php

declare(strict_types=1);

use App\Models\Masters\Star;
use Laravel\Dusk\Browser;

/*
| P1.8 — the A11 list editor in a real browser: add a row, move it up with the arrow button
| (the keyboard alternative to drag-and-drop). The throwaway row and admin are cleaned up.
*/

beforeEach(function (): void {
    Star::query()->where('code', 'DUSK_STAR')->delete();
    duskAdminCleanup();
});

afterEach(function (): void {
    Star::query()->where('code', 'DUSK_STAR')->delete();
    duskAdminCleanup();
});

it('P1.8: adds a star and moves it up the list', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/masters/stars'))
            ->waitForText('Add a row')
            ->type('#f-newCode', 'DUSK_STAR')
            ->type('#f-newLabel', 'Dusk star')
            ->press('Add')
            ->waitForText('Dusk star');

        // A reorder renumbers the whole list (10, 20, 30…), so compare positions, not sort_order values.
        $position = fn (): int|false => array_search('DUSK_STAR', Star::query()->orderBy('sort_order')->orderBy('label')->pluck('code')->all(), true);
        $last = Star::query()->count() - 1;
        expect($position())->toBe($last);

        $browser->click('button[aria-label="Move Dusk star up"]')
            ->waitUsing(5, 100, fn (): bool => $position() === $last - 1);
    });

    $ids = Star::query()->orderBy('sort_order')->pluck('code')->all();
    expect(array_search('DUSK_STAR', $ids, true))->toBe(count($ids) - 2);
});
