<?php

declare(strict_types=1);

use App\Actions\Profile\Photos\DeleteProfilePhoto;
use App\Actions\Profile\Photos\MoveProfilePhoto;
use App\Actions\Profile\Photos\UpdatePhotoCaption;
use App\Actions\Profile\Photos\UploadProfilePhoto;
use App\Actions\Profile\SubmitProfile;
use App\Enums\ModerationItemType;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| P1.4 — M11 photo Actions: upload (validation, sanitising, limits, moderation item), delete,
| reorder / primary, caption; the security matrix; completeness and submit with a photo.
*/

beforeEach(function (): void {
    seedMasters();
    fakeMediaDisks();
});

function uploadPhoto(User $user, UploadedFile $file, ?string $caption = null): Media
{
    return app(UploadProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), $file, $caption);
}

function photoErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $e) {
        return $e->errors()['photo'] ?? array_keys($e->errors());
    }

    return [];
}

/** @return list<string> uuids in display order */
function photoOrder(User $user): array
{
    $profile = $user->profile()->firstOrFail();

    return Media::query()->where('model_id', $profile->id)->where('collection_name', Profile::PHOTOS)
        ->orderBy('order_column')->pluck('uuid')->map(fn ($uuid): string => (string) $uuid)->all();
}

// ---- Upload -----------------------------------------------------------------------------------

it('M11: an upload is stored PENDING with a perceptual hash and opens a PHOTO item in the A04 queue', function (): void {
    Event::fake([AdminQueueCountChanged::class]);
    $user = memberThroughStep(5);

    $media = uploadPhoto($user, UploadedFile::fake()->image('me.png', 800, 1000), '  At Munnar  ');

    expect($media->moderation_status)->toBe(PhotoStatus::Pending)
        ->and($media->phash)->toMatch('/^[0-9a-f]{16}$/')
        ->and($media->caption)->toBe('At Munnar')
        ->and($media->mime_type)->toBe('image/jpeg')                       // re-encoded
        ->and($media->disk)->toBe(config('oppam.media.private_disk'))     // original stays private
        ->and($media->getPath())->toContain((string) $media->uuid)        // uuid path, not the numeric id
        ->and(ModerationItem::query()->ofType(ModerationItemType::Photo)->where('subject_id', $media->uuid)->exists())->toBeTrue();

    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->broadcastWith() === ['queue' => 'moderation.photos', 'count' => 1]);
});

it('M11: EXIF (including GPS) is stripped from the stored original', function (): void {
    $user = memberThroughStep(5);
    $source = jpegWithGps('gps.jpg');
    $withExif = (string) file_get_contents($source->getRealPath());

    $media = uploadPhoto($user, $source);

    $stored = (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    expect($withExif)->toContain('GPSLatitude')
        ->and($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('GPSLatitude');
});

it('refuses files that are not real photos, too small or too large', function (Closure $file, string $message): void {
    $user = memberThroughStep(5);

    expect(implode(' ', photoErrors(fn () => uploadPhoto($user, $file()))))->toContain($message)
        ->and(Media::query()->count())->toBe(0);
})->with([
    'PHP script renamed .jpg' => [fn () => UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]); ?>'), 'photo'],
    'SVG renamed .jpg' => [fn () => UploadedFile::fake()->createWithContent('photo.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'photo'],
    'GIF' => [fn () => UploadedFile::fake()->image('photo.gif', 600, 600), 'photo'],
    'smaller than 400 px' => [fn () => UploadedFile::fake()->image('small.jpg', 399, 800), 'dimensions'],
    'over 10 MB' => [fn () => UploadedFile::fake()->image('huge.jpg', 600, 800)->size(10241), '10240'],
]);

it('M11: allows 10 photos; the 11th is refused, rejected photos don\'t count', function (): void {
    $user = memberThroughStep(5);
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);

    foreach (range(1, 10) as $i) {
        uploadPhoto($user, UploadedFile::fake()->image("p{$i}.jpg", 500, 500));
    }

    expect(implode(' ', photoErrors(fn () => uploadPhoto($user, UploadedFile::fake()->image('p11.jpg', 500, 500)))))->toContain('up to 10');

    Media::query()->firstOrFail()->forceFill(['moderation_status' => PhotoStatus::Rejected])->save();
    uploadPhoto($user, UploadedFile::fake()->image('again.jpg', 500, 500));

    expect(Media::query()->count())->toBe(11);
});

it('rate-limits uploads per member', function (): void {
    $user = memberThroughStep(5);
    RateLimiter::increment('media-upload:'.$user->id, amount: (int) config('oppam.media.uploads_per_hour'));

    expect(implode(' ', photoErrors(fn () => uploadPhoto($user, UploadedFile::fake()->image('p.jpg', 500, 500)))))->toContain('try again')
        ->and(Media::query()->count())->toBe(0);
});

it('puts a paid member\'s photo in the priority lane', function (): void {
    $user = memberThroughStep(5);
    $user->profile->forceFill(['is_premium' => true])->save();

    $media = uploadPhoto($user->refresh(), UploadedFile::fake()->image('p.jpg', 500, 500));

    expect(ModerationItem::query()->where('subject_id', $media->uuid)->value('is_priority'))->toBeTruthy();
});

// ---- Completeness & submit ---------------------------------------------------------------------

it('R-M02-3: the first photo adds 15 to completeness and deleting the last one takes it away', function (): void {
    $user = memberThroughStep(5);
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData());
    expect($user->profile()->firstOrFail()->completeness)->toBe(85);

    $media = uploadPhoto($user, UploadedFile::fake()->image('p.jpg', 500, 500));
    expect($user->profile()->firstOrFail()->completeness)->toBe(100);

    app(DeleteProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), (string) $media->uuid);
    expect($user->profile()->firstOrFail()->completeness)->toBe(85);
});

it('M02 step 6: a profile can\'t be submitted without a photo', function (): void {
    $user = memberThroughStep(5);
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData());

    expect(fn () => app(SubmitProfile::class)->handle($user, $user->profile()->firstOrFail()))
        ->toThrow(ProfileNotSubmittable::class, 'at least one photo');
});

// ---- Delete, reorder, caption -------------------------------------------------------------------

it('deletes a photo, its files and its waiting review', function (): void {
    $user = memberThroughStep(5);
    $media = uploadPhoto($user, UploadedFile::fake()->image('p.jpg', 500, 500));
    $path = $media->getPathRelativeToRoot();

    app(DeleteProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), (string) $media->uuid);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk($media->disk)->exists($path))->toBeFalse()
        ->and(ModerationItem::query()->ofType(ModerationItemType::Photo)->count())->toBe(0);
});

it('moves photos: make primary, move later, and clamps any position from the browser', function (): void {
    $user = memberThroughStep(5);
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
    [$a, $b, $c] = array_map(fn (int $i): string => (string) uploadPhoto($user, UploadedFile::fake()->image("p{$i}.jpg", 500, 500))->uuid, [1, 2, 3]);
    $move = fn (string $uuid, int $to) => app(MoveProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), $uuid, $to);

    $move($c, 0);
    expect(photoOrder($user))->toBe([$c, $a, $b]);

    $move($c, 1);
    expect(photoOrder($user))->toBe([$a, $c, $b]);

    $move($a, 999);
    expect(photoOrder($user))->toBe([$c, $b, $a]);

    $move($b, -5);
    expect(photoOrder($user))->toBe([$b, $c, $a]);
});

it('a new caption on an APPROVED photo goes back to review; on a pending photo it doesn\'t add an item', function (): void {
    $user = memberThroughStep(5);
    $pending = uploadPhoto($user, UploadedFile::fake()->image('p.jpg', 500, 500));
    $approved = uploadPhoto($user, UploadedFile::fake()->image('q.jpg', 500, 500));
    $approved->forceFill(['moderation_status' => PhotoStatus::Approved])->save();
    $caption = fn (Media $m, ?string $text) => app(UpdatePhotoCaption::class)->handle($user, $user->profile()->firstOrFail(), (string) $m->uuid, $text);
    $items = fn (): int => ModerationItem::query()->ofType(ModerationItemType::Photo)->count();

    $before = $items();
    $caption($pending, 'Family function');
    expect($items())->toBe($before);

    $caption($approved, 'Call me on 98470 12345');
    expect($items())->toBe($before + 1)
        ->and(ModerationItem::query()->latest('submitted_at')->latest('id')->first()?->fields)->toBe(['caption' => 'Call me on 98470 12345'])
        ->and($approved->refresh()->caption)->toBe('Call me on 98470 12345');

    expect(fn () => $caption($pending, str_repeat('x', 101)))->toThrow(ValidationException::class);
});

// ---- Security matrix -----------------------------------------------------------------------------

it('security matrix: refuses another member, a suspended member and a suspended profile', function (Closure $arrange): void {
    $owner = memberThroughStep(5);
    $actor = $arrange($owner) ?? $owner;

    expect(fn () => app(UploadProfilePhoto::class)->handle($actor->refresh(), $owner->profile()->firstOrFail(), UploadedFile::fake()->image('p.jpg', 500, 500)))
        ->toThrow(AuthorizationException::class)
        ->and(Media::query()->count())->toBe(0);
})->with([
    'another member' => fn (User $u) => memberThroughStep(5),
    'suspended member' => fn (User $u) => $u->forceFill(['status' => UserStatus::Suspended])->save() ? null : null,
    'suspended profile' => fn (User $u) => $u->profile->forceFill(['status' => ProfileStatus::Suspended])->save() ? null : null,
]);

it('IDOR: a photo uuid of another profile is "not found" for delete, move and caption', function (string $action): void {
    $victim = memberThroughStep(5);
    $photo = uploadPhoto($victim, UploadedFile::fake()->image('p.jpg', 500, 500));
    $attacker = memberThroughStep(5);
    $profile = $attacker->profile()->firstOrFail();
    $uuid = (string) $photo->uuid;

    $attempt = match ($action) {
        'delete' => fn () => app(DeleteProfilePhoto::class)->handle($attacker, $profile, $uuid),
        'move' => fn () => app(MoveProfilePhoto::class)->handle($attacker, $profile, $uuid, 0),
        'caption' => fn () => app(UpdatePhotoCaption::class)->handle($attacker, $profile, $uuid, 'hacked'),
    };

    expect($attempt)->toThrow(ModelNotFoundException::class)
        ->and($photo->refresh()->caption)->toBeNull()
        ->and(Media::query()->whereKey($photo->id)->exists())->toBeTrue();
})->with(['delete', 'move', 'caption']);

it('a live profile may still add photos; they wait for review', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();

    $media = uploadPhoto($user->refresh(), UploadedFile::fake()->image('new.jpg', 500, 500));

    expect($media->moderation_status)->toBe(PhotoStatus::Pending);
});
