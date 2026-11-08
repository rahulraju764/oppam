<?php

declare(strict_types=1);

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileClosure;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;

/*
| P8.2c — /settings → Hide / delete (M14) in a real browser at 375 px: hide and show the profile,
| close the account through the dialog, then sign in again and restore it (rule 5 flow). Then the
| tab at 320 / 768 / 1024 / 1440 px. Against the dev server and database: the Dusk member is
| removed before and after.
*/

function duskCloseCleanup(): void
{
    $user = User::withTrashed()->where('phone', DUSK_MEMBER_PHONE)->first();
    if ($user !== null) {
        RateLimiter::clear('close-account:'.$user->id);
        RateLimiter::clear('login:id:'.sha1(DUSK_MEMBER_PHONE));
        $profileId = Profile::withTrashed()->where('user_id', $user->id)->value('id');
        if (is_string($profileId)) {
            RateLimiter::clear('profile-visibility:'.$profileId);
        }
        ProfileClosure::query()->where('user_id', $user->id)->delete();
    }
    duskMemberCleanup();
}

beforeEach(fn () => duskCloseCleanup());
afterEach(fn () => duskCloseCleanup());

it('M14 at 375 px: hide → show again → close the account → sign in → restore it', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        $member = duskLiveMember($browser);
        $browser->resize(375, 812);

        $browser->visit('/settings?tab=manage')->waitFor('@manage-hide', 15);
        assertNoHorizontalScroll($browser);

        $browser->script('document.querySelector("[dusk=manage-hide]").scrollIntoView({block: "center"});');
        $browser->click('@manage-hide')->waitFor('@manage-unhide', 10);
        expect($member->profile()->firstOrFail()->status)->toBe(ProfileStatus::Hidden);

        $browser->click('@manage-unhide')->waitFor('@manage-hide', 10);
        expect($member->profile()->firstOrFail()->status)->toBe(ProfileStatus::Active);

        // Close through the dialog.
        $browser->script('document.querySelector("[dusk=manage-close]").scrollIntoView({block: "center"});');
        $browser->click('@manage-close')->waitFor('#closeReason-NOT_INTERESTED', 5)
            ->radio('closeReason', 'NOT_INTERESTED')
            ->waitFor('#closePassword', 5)
            ->type('#closePassword', 'kerala2026')
            ->click('@close-confirm')
            ->waitForLocation('/', 15)
            ->waitForText('Your account is closed', 10);
        expect(User::withTrashed()->findOrFail($member->id)->trashed())->toBeTrue();

        // Sign in again → restore.
        $browser->visit('/login')
            ->type('#loginId', '9999900001')
            ->type('#loginPassword', 'kerala2026')
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/account/restore', 15)
            ->waitForText('Your account is closed', 10);
        assertNoHorizontalScroll($browser);

        $browser->press('Restore my account')->waitUntilMissingText('Your account is closed', 15);
        $restored = User::query()->findOrFail($member->id);
        expect($restored->trashed())->toBeFalse()
            ->and($restored->profile()->firstOrFail()->status)->toBe(ProfileStatus::Active);
    });
});

it('the Hide / delete tab fits 320 / 768 / 1024 / 1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskLiveMember($browser);

        foreach ([320, 768, 1024, 1440] as $width) {
            $browser->resize($width, 900)->visit('/settings?tab=manage')->waitFor('@manage-close', 15);
            assertNoHorizontalScroll($browser);
        }
    });
});
