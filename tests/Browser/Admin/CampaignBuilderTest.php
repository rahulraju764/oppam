<?php

declare(strict_types=1);

use App\Models\Campaign;
use Laravel\Dusk\Browser;

/*
| P8.3b1 — A14 campaign screens in a real browser (`composer dev` running): build a draft in the
| builder (live audience), open its page, and check the three screens fit 320 – 1440 px. The draft
| is never sent (no member is messaged) and is deleted afterwards with the throwaway admin.
*/

const DUSK_CAMPAIGN_NAME = 'Dusk campaign (delete me)';

beforeEach(function (): void {
    Campaign::query()->where('name', DUSK_CAMPAIGN_NAME)->delete();
    duskAdminCleanup();
});

afterEach(function (): void {
    Campaign::query()->where('name', DUSK_CAMPAIGN_NAME)->delete();
    duskAdminCleanup();
});

it('P8.3b1: builds a draft campaign and opens its page; the screens fit every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/comms/campaigns/create'))
            ->waitForText('Audience now')
            ->type('#cmpName', DUSK_CAMPAIGN_NAME)
            ->type('#cmpSubject', 'Hello {first_name}')
            ->type('#cmpMessage', 'A test draft from Dusk.')
            ->select('#cmpGender', 'FEMALE')
            ->pause(600)   // the debounced estimate
            ->press('@campaign-save')
            ->waitForText('Next step')
            ->assertSee(DUSK_CAMPAIGN_NAME)
            ->assertSee('Draft')
            ->assertPresent('@campaign-submit');

        $code = Campaign::query()->where('name', DUSK_CAMPAIGN_NAME)->value('code');
        expect($code)->toMatch('/^CMP[A-Z0-9]{8}$/');

        assertFitsWidths($browser, adminDuskUrl('/'), [
            adminDuskUrl('/comms/campaigns'),
            adminDuskUrl('/comms/campaigns/create'),
            adminDuskUrl('/comms/campaigns/'.$code),
        ]);
    });
});
