<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
| P1.7a — A03 member management in a real browser at phone width (375px): search the list, open
| a member, add a note on the lazy Notes tab, suspend with a typed reason from the dialog — and
| no page ever scrolls sideways. Throwaway member + admin are cleaned up around the test.
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

afterEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

it('P1.7a: finds a member, adds a note and suspends them at 375px', function (): void {
    $user = User::factory()->create(['phone' => DUSK_MEMBER_PHONE]);
    $profile = Profile::factory()->for($user)->active()->create();

    $this->browse(function (Browser $browser) use ($profile): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/members'))
            ->waitFor('.admin-page-head')
            ->type('#f-q', $profile->code)
            ->waitForLink($profile->code)
            ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth')
            ->clickLink($profile->code)
            ->waitForText('Quick actions')
            ->assertScript('document.scrollingElement.scrollWidth <= document.documentElement.clientWidth');

        // Lazy Notes tab.
        $browser->press('Notes')
            ->waitFor('#f-noteBody')
            ->type('#f-noteBody', 'Called the member from Dusk.')
            ->press('Add note')
            ->waitForText('Called the member from Dusk.');

        // Suspend through the typed-reason dialog.
        $browser->press('Overview')
            ->waitForText('Quick actions')
            ->press('Suspend')
            ->waitFor('#member-suspend-reason')
            ->type('#member-suspend-reason', 'Dusk: confirmed fake profile.')
            ->within('[aria-labelledby="modal-member-suspend-title"]', fn (Browser $dialog) => $dialog->press('Suspend'))
            ->waitForText('Member suspended.')
            ->assertSee('Reactivate');
    });

    expect($user->refresh()->status)->toBe(UserStatus::Suspended);
});
