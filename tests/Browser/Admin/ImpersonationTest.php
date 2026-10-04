<?php

declare(strict_types=1);

use App\Enums\ImpersonationEndReason;
use App\Models\ImpersonationSession;
use App\Models\Profile;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
| P1.7b — admin impersonation end to end in a real browser, across the two domains: the admin
| opens a member, starts "View as member" with a reason, lands on the member site with the
| support banner, and ends the session back in the admin panel.
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

it('P1.7b: an admin views the site as a member and ends the session', function (): void {
    $user = User::factory()->create(['phone' => DUSK_MEMBER_PHONE]);
    $profile = Profile::factory()->for($user)->active()->create();

    $this->browse(function (Browser $browser) use ($profile): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/members/'.$profile->code))
            ->waitForText('Quick actions')
            ->press('View as member')
            ->waitFor('#member-impersonate-reason')
            ->type('#member-impersonate-reason', 'Dusk: member asked for help with the wizard.')
            ->within('[aria-labelledby="modal-member-impersonate-title"]', fn (Browser $dialog) => $dialog->press('Start (30 minutes)'))
            ->waitForText('Support view')
            ->assertSee($profile->code)
            ->press('End session')
            ->waitForText('Support session ended')
            // Opened directly: locally admin.localhost and localhost are different sites, so a
            // link click would hold back the SameSite=Strict admin cookie (in production
            // oppam.in / admin.oppam.in are one site). The admin session survived throughout.
            ->visit(adminDuskUrl('/members/'.$profile->code))
            ->waitForText('Quick actions')
            ->assertSee($profile->code)
            ->assertDontSee('Support view');
    });

    expect(ImpersonationSession::query()->where('user_id', $user->id)->sole()->end_reason)->toBe(ImpersonationEndReason::Ended);
});
