<?php

declare(strict_types=1);

use App\Domain\Media\PhotoUrls;
use App\Enums\PhotoStatus;
use App\Enums\PhotoVisibility;
use App\Enums\UserStatus;
use App\Models\Media;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
| P1.4 "Done when": non-permitted viewers only ever receive the blurred URL; pending photos are
| visible to the owner only (M11, PhotoAccess + PhotoUrls).
*/

beforeEach(function (): void {
    seedMasters();
    fakeMediaDisks();
});

/** An onboarded member whose single photo is APPROVED (conversions generated), with a visibility. */
function memberWithApprovedPhoto(PhotoVisibility $visibility = PhotoVisibility::AllMembers): User
{
    $user = memberThroughStep(5);
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData(['photo_visibility' => $visibility->value]));
    app(App\Actions\Profile\Photos\UploadProfilePhoto::class)
        ->handle($user, $user->profile()->firstOrFail(), gradientPhoto())
        ->forceFill(['moderation_status' => PhotoStatus::Approved])->save();
    $user->profile->forceFill(['status' => App\Enums\ProfileStatus::Active, 'published_at' => now()])->save();

    return $user->refresh();
}

function viewer(string $kind): ?User
{
    return match ($kind) {
        'guest' => null,
        'free member' => groom('+919800000001'),
        'premium member' => tap(groom('+919800000002'), fn (User $u) => $u->profile->forceFill(['is_premium' => true])->save()),
        'suspended member' => tap(groom('+919800000003'), fn (User $u) => $u->forceFill(['status' => UserStatus::Suspended])->save()),
        'broker login' => User::factory()->broker()->create(),
    };
}

it('Done when: viewers see the clear photo only when the visibility allows, otherwise ONLY the blurred URL', function (PhotoVisibility $visibility, string $kind, string $sees): void {
    $owner = memberWithApprovedPhoto($visibility);
    $media = Media::query()->firstOrFail();

    $photos = app(PhotoUrls::class)->forViewer($owner->profile()->firstOrFail(), viewer($kind));

    // Members who may not open the profile at all (suspended, broker logins) get nothing.
    if ($sees === 'none') {
        expect($photos)->toBe([]);

        return;
    }

    expect($photos)->toHaveCount(1);
    $photo = $photos[0];

    if ($sees === 'clear') {
        expect($photo->blurred)->toBeFalse()
            ->and($photo->cardUrl)->toBe($media->getUrl('card'))
            ->and($photo->fullUrl)->toBe($media->getUrl('full'));

        return;
    }

    $blurred = $media->getUrl('blurred');
    expect($photo->blurred)->toBeTrue()
        ->and([$photo->thumbUrl, $photo->cardUrl, $photo->fullUrl])->each->toBe($blurred)
        ->and(serialize($photos))->not->toContain($media->getUrl('card'))
        ->and(serialize($photos))->not->toContain($media->getUrl('full'))
        ->and(serialize($photos))->not->toContain($media->getUrl('thumb'));
})->with([
    'all members → guest' => [PhotoVisibility::AllMembers, 'guest', 'blurred'],
    'all members → free member' => [PhotoVisibility::AllMembers, 'free member', 'clear'],
    'all members → suspended member' => [PhotoVisibility::AllMembers, 'suspended member', 'none'],
    'all members → broker login' => [PhotoVisibility::AllMembers, 'broker login', 'none'],
    'premium only → free member' => [PhotoVisibility::PremiumOnly, 'free member', 'blurred'],
    'premium only → premium member' => [PhotoVisibility::PremiumOnly, 'premium member', 'clear'],
    'on request → premium member (not connected)' => [PhotoVisibility::OnRequest, 'premium member', 'blurred'],
    'accepted only → premium member (not connected)' => [PhotoVisibility::AcceptedOnly, 'premium member', 'blurred'],
]);

it('the owner always sees their photos clearly, whatever the visibility', function (): void {
    $owner = memberWithApprovedPhoto(PhotoVisibility::AcceptedOnly);

    $photo = app(PhotoUrls::class)->forViewer($owner->profile()->firstOrFail(), $owner)[0];

    expect($photo->blurred)->toBeFalse()
        ->and($photo->status)->toBe(PhotoStatus::Approved);
});

it('Done when: pending (and rejected) photos are visible to the owner only, with their label', function (PhotoStatus $status): void {
    $owner = memberThroughStep(5);
    addTestPhoto($owner, convert: true)->forceFill(['moderation_status' => $status])->save();
    $profile = $owner->profile()->firstOrFail();
    $urls = app(PhotoUrls::class);

    expect($urls->forViewer($profile, $owner))->toHaveCount(1)
        ->and($urls->forViewer($profile, $owner)[0]->status)->toBe($status)
        ->and($urls->forViewer($profile, groom('+919800000009')))->toBe([])
        ->and($urls->forViewer($profile, null))->toBe([])
        ->and($urls->primaryCardUrl($profile, groom('+919800000008')))->toBeNull();
})->with([PhotoStatus::Pending, PhotoStatus::Rejected]);

it('other viewers never see the moderation status or rejection reason', function (): void {
    $owner = memberWithApprovedPhoto();
    Media::query()->firstOrFail()->forceFill(['rejection_reason' => 'internal note'])->save();

    $photo = app(PhotoUrls::class)->forViewer($owner->profile()->firstOrFail(), groom('+919800000007'))[0];

    expect($photo->status)->toBeNull()->and($photo->rejectionReason)->toBeNull();
});

it('generates thumb, card, watermarked full and blurred conversions on the public disk under the uuid', function (): void {
    memberWithApprovedPhoto();
    $media = Media::query()->firstOrFail();

    foreach (['thumb', 'card', 'full', 'blurred'] as $conversion) {
        expect($media->hasGeneratedConversion($conversion))->toBeTrue()
            ->and($media->getUrl($conversion))->toContain((string) $media->uuid)
            ->and(Storage::disk((string) config('oppam.media.public_disk'))->exists($media->getPathRelativeToRoot($conversion)))->toBeTrue();
    }

    expect(Storage::disk((string) config('oppam.media.public_disk'))->exists($media->getPathRelativeToRoot()))->toBeFalse();   // original: private only

    // The blurred version really is degraded: far fewer distinct colours than the card.
    $colours = function (string $conversion) use ($media): int {
        $image = imagecreatefromstring((string) Storage::disk((string) config('oppam.media.public_disk'))->get($media->getPathRelativeToRoot($conversion)));
        $seen = [];
        for ($x = 0; $x < imagesx($image); $x += 7) {
            for ($y = 0; $y < imagesy($image); $y += 7) {
                $seen[imagecolorat($image, $x, $y)] = true;
            }
        }

        return count($seen);
    };

    expect($colours('blurred'))->toBeLessThan($colours('card'));
});

it('visibility defaults to all members when the member never chose one', function (): void {
    $owner = memberWithApprovedPhoto();
    PrivacySetting::query()->whereKey($owner->profile->id)->delete();

    expect(app(PhotoUrls::class)->forViewer($owner->profile()->firstOrFail()->unsetRelations(), groom('+919800000006'))[0]->blurred)->toBeFalse();
});

it('P1.4 review Blocker: the clear versions can\'t be derived from the blurred URL', function (): void {
    memberWithApprovedPhoto(PhotoVisibility::PremiumOnly);
    $media = Media::query()->firstOrFail();
    $disk = Storage::disk((string) config('oppam.media.public_disk'));

    $blurredPath = $media->getPathRelativeToRoot('blurred');
    $blurredName = pathinfo($blurredPath, PATHINFO_FILENAME);

    // The names share nothing a viewer could edit: no common stem, no "-blurred" suffix to swap.
    foreach (['thumb', 'card', 'full'] as $conversion) {
        $clearName = pathinfo($media->getPathRelativeToRoot($conversion), PATHINFO_FILENAME);

        expect($clearName)->not->toContain($blurredName)
            ->and($blurredName)->not->toContain($clearName)
            ->and($disk->exists(dirname($blurredPath).'/'.pathinfo((string) $media->file_name, PATHINFO_FILENAME).'-'.$conversion.'.webp'))->toBeFalse();
    }

    expect($blurredPath)->not->toContain('blurred')
        ->and($blurredPath)->not->toContain(pathinfo((string) $media->file_name, PATHINFO_FILENAME));
});
