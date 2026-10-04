<?php

declare(strict_types=1);

use App\Models\ImpersonationSession;
use App\Models\Profile;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
| P1.7b — the support-view banner at phone width (375px device emulation): readable, its End
| button reachable, and the member page never scrolls sideways while it shows.
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

afterEach(function (): void {
    ImpersonationSession::query()->whereHas('user', fn ($q) => $q->where('phone', DUSK_MEMBER_PHONE))->delete();
    duskMemberCleanup();
    duskAdminCleanup();
});

it('P1.7b: the impersonation banner fits a phone screen', function (): void {
    $user = User::factory()->create(['phone' => DUSK_MEMBER_PHONE]);
    $profile = Profile::factory()->for($user)->active()->create();

    $this->browse(function (Browser $browser) use ($profile): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/members/'.$profile->code))
            ->waitForText('Quick actions')
            ->press('View as member')
            ->waitFor('#member-impersonate-reason')
            ->type('#member-impersonate-reason', 'Dusk: checking the banner on a phone.')
            ->within('[aria-labelledby="modal-member-impersonate-title"]', fn (Browser $dialog) => $dialog->press('Start (30 minutes)'))
            ->waitForText('Support view')
            ->assertVisible('.impersonation-banner button[type="submit"]');

        assertNoHorizontalScroll($browser);

        $browser->press('End session')->waitForText('Support session ended');
    });
});
