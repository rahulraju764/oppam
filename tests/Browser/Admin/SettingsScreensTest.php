<?php

declare(strict_types=1);

use App\Models\Setting;
use Laravel\Dusk\Browser;

/*
| P8.4 — A15 settings screens in a real browser (`composer dev` running): change one business rule
| through its reason dialog, and check the five settings screens fit 320 – 1440 px. The rule is put
| back as it was afterwards.
*/

beforeEach(function (): void {
    $this->ruleBefore = Setting::query()->find('matching.daily_match_count')?->value;
    duskAdminCleanup();
});

afterEach(function (): void {
    $this->ruleBefore === null
        ? Setting::query()->whereKey('matching.daily_match_count')->delete()
        : Setting::query()->whereKey('matching.daily_match_count')->update(['value' => json_encode($this->ruleBefore)]);
    app(App\Services\Settings\SettingsRepository::class)->flush();
    duskAdminCleanup();
});

it('P8.4: changes a business rule through the reason dialog; every settings screen fits every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/settings/rules'))
            ->waitForText('Business rules')
            ->press('@rule-edit-matching.daily_match_count')
            ->waitFor('#rule-value')
            ->clear('#rule-value')->type('#rule-value', '12')
            ->type('#rule-edit-reason', 'Dusk check (restored after)')
            ->press('Save rule')
            ->waitForText('saved');

        expect(Setting::query()->find('matching.daily_match_count')?->value)->toBe(12);

        assertFitsWidths($browser, adminDuskUrl('/'), [
            adminDuskUrl('/settings/site'),
            adminDuskUrl('/settings/rules'),
            adminDuskUrl('/settings/features'),
            adminDuskUrl('/settings/entitlements'),
            adminDuskUrl('/settings/integrations'),
        ]);
    });
});
