<?php

declare(strict_types=1);

use App\Actions\Profile\SubmitProfile;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Dusk\Browser;

/*
| P3.5 — "Done when" in a real browser over Reverb (needs `composer dev`): an admin has the
| dashboard open; a member submits their profile; the sidebar's "Profile queue" badge and the
| dashboard's "Profiles to moderate" card go up by one without a reload (F01, PRD §11.0, A02).
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

afterEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

it('P3.5: submitting a profile raises the moderation badge and the dashboard card live', function (): void {
    // A member who has finished the wizard (through the real Actions) and is about to submit.
    $user = User::factory()->create(['phone' => DUSK_MEMBER_PHONE]);
    $profile = Profile::factory()->for($user)->draft()->create(['gender' => Gender::Female]);
    app(App\Actions\Profile\SaveBasicDetails::class)->handle($user, $profile, basicData(['gender' => Gender::Female->value]));
    app(App\Actions\Profile\SaveCareerDetails::class)->handle($user, $profile->refresh(), careerData());
    app(App\Actions\Profile\SaveFamilyDetails::class)->handle($user, $profile->refresh(), familyData());
    app(App\Actions\Profile\SavePartnerPreferences::class)->handle($user, $profile->refresh(), preferenceData());
    app(App\Actions\Profile\SaveContactDetails::class)->handle($user, $profile->refresh(), contactData());
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $profile->refresh(), aboutData());
    app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle(
        $user, $profile->refresh(), new UploadedFile(duskPhotoFixture(), 'photo.jpg', 'image/jpeg', null, true),
    );

    $this->browse(function (Browser $browser) use ($user): void {
        duskSignInAdmin($browser);

        $number = fn (string $selector): int => (int) preg_replace('/\D/', '', (string) $browser->script(
            "return document.querySelector('{$selector}')?.textContent ?? '0';")[0]);
        $badge = '.admin-nav-badge[data-queue="moderation.profiles"]';
        $card = '[data-queue-count="moderation.profiles"]';

        $browser->resize(1280, 900)->visit(adminDuskUrl('/'))
            ->waitForText('Work queues')
            ->waitUsing(15, 100, fn (): bool => (bool) $browser->script("return window.Alpine?.store('adminLive')?.status === 'up';")[0]);

        $before = $number($badge);
        expect($number($card))->toBe($before);

        app(SubmitProfile::class)->handle($user->refresh(), $user->profile()->firstOrFail());   // the queue worker broadcasts the new count
        expect($user->profile()->firstOrFail()->status)->toBe(ProfileStatus::PendingReview);

        $browser->waitUsing(20, 200, fn (): bool => $number($badge) === $before + 1)
            ->waitUsing(20, 200, fn (): bool => $number($card) === $before + 1)
            ->assertVisible($badge);
    });
});
