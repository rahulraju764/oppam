<?php

declare(strict_types=1);

use App\Enums\CommsChannel;
use App\Models\AutomatedJourney;
use Laravel\Dusk\Browser;

/*
| P8.3b2 — the A14 Journeys screen in a real browser (`composer dev` running): open the edit dialog,
| change the days with a reason and save; the screen fits 320 – 1440 px. The journey is put back
| as it was (OFF) afterwards, so the dev site never starts sending reminders because of this test.
*/

beforeEach(function (): void {
    $this->journeyBefore = AutomatedJourney::query()->where('key', 'win_back')->first()?->only(['is_active', 'offsets', 'channel']);
    duskAdminCleanup();
});

afterEach(function (): void {
    if ($this->journeyBefore !== null) {
        AutomatedJourney::query()->where('key', 'win_back')->first()?->forceFill($this->journeyBefore)->save();
    }
    duskAdminCleanup();
});

it('P8.3b2: changes a journey\'s days through the reason dialog; the screen fits every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/comms/journeys'))
            ->waitForText('Automated journeys')
            ->assertSee('Plan expiry reminders')
            ->press('@journey-edit-win_back')
            ->waitFor('#journey-offset-0')
            ->clear('#journey-offset-0')->type('#journey-offset-0', '45')
            ->type('#journey-edit-reason', 'Dusk check (restored after)')
            ->press('Save journey')
            ->waitForText('Journey saved');

        expect(AutomatedJourney::query()->where('key', 'win_back')->sole()->offsets)->toBe([45])
            ->and(AutomatedJourney::query()->where('key', 'win_back')->sole()->channel)->toBe(CommsChannel::Email);

        assertFitsWidths($browser, adminDuskUrl('/'), [adminDuskUrl('/comms/journeys')]);
    });
});
