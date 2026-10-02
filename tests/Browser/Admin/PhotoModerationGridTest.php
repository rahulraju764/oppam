<?php

declare(strict_types=1);

use App\Enums\PhotoStatus;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Dusk\Browser;

/*
| P1.6 — A04 photo grid with the keyboard in a real browser: a super admin focuses the first photo,
| presses Space (reveal), A (approve) and Enter (apply); the photo is approved. Throwaway member
| (DUSK_MEMBER_PHONE) and admin are cleaned up around the test.
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

afterEach(function (): void {
    duskMemberCleanup();
    duskAdminCleanup();
});

it('P1.6: approves a waiting photo with the keyboard (Space, A, Enter)', function (): void {
    $user = User::factory()->create(['phone' => DUSK_MEMBER_PHONE]);
    $profile = Profile::factory()->for($user)->active()->create();
    $photo = app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle(
        $user, $profile, new UploadedFile(duskPhotoFixture(), 'photo.jpg', 'image/jpeg', null, true),
    );

    $this->browse(function (Browser $browser) use ($profile): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/moderation/photos'))
            ->waitForText($profile->code)
            ->click('.mod-tile')
            ->keys('.mod-tile', ' ')
            ->waitFor('.mod-tile.is-revealed')
            ->keys('.mod-tile', 'a')
            ->waitFor('.mod-tile.is-approve')
            ->keys('.mod-tile', '{enter}')
            ->waitForText('1 photo decided.');
    });

    expect(Media::query()->whereKey($photo->id)->firstOrFail()->moderation_status)->toBe(PhotoStatus::Approved);
});
