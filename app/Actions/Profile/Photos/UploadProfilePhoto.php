<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Domain\Media\PerceptualHash;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Media\ImageSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Add a profile photo (M11): JPG/PNG/WebP ≤ 10 MB, at least 400 × 400, at most 10 per profile
 * (rejected ones don't count), rate-limited per member. The file is re-encoded first (EXIF/GPS
 * stripped, orientation applied, renamed scripts refused), its perceptual hash stored for
 * duplicate checks, and it starts PENDING with a PHOTO item in the A04 queue — only approved
 * photos are shown to others. Conversions run on the `media` queue after commit.
 */
final class UploadProfilePhoto
{
    use ManagesOwnPhotos;

    public function __construct(
        private readonly ImageSanitizer $sanitizer,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, UploadedFile $file, ?string $caption = null): Media
    {
        $this->authorizePhotos($actor, $profile);
        $this->validate($file, $caption);

        $key = 'media-upload:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, (int) config('oppam.media.uploads_per_hour'))) {
            throw ValidationException::withMessages(['photo' => __('You have uploaded a lot of photos. Please try again in an hour.')]);
        }

        $media = $this->storeChecked($actor, $profile, $file, $caption);

        $this->limiter->hit($key, 3600);

        return $media;
    }

    /**
     * The same photo pipeline without the per-login hourly cap — for the bulk import (§11A B.9
     * step 5 "photos through the normal media pipeline"), whose volume the row caps bound
     * (R-M13-31). Authorization, file rules, re-encoding, the per-profile limit and the PENDING
     * A04 item are all unchanged.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function storeChecked(User $actor, Profile $profile, UploadedFile $file, ?string $caption = null): Media
    {
        $this->authorizePhotos($actor, $profile);
        $this->validate($file, $caption);

        $config = (array) config('oppam.media');

        try {
            $clean = $this->sanitizer->sanitize((string) $file->getRealPath());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['photo' => __('This file is not a photo we can read. Please choose a JPG, PNG or WebP image.')]);
        }

        try {
            $media = DB::transaction(fn (): Media => $this->store($profile, $clean['path'], $caption, (int) $config['max_photos']));
        } finally {
            @unlink($clean['path']);
        }

        return $media;
    }

    /** @throws ValidationException */
    private function validate(UploadedFile $file, ?string $caption): void
    {
        $config = (array) config('oppam.media');
        $minPx = (int) $config['photo_min_px'];

        Validator::make(['photo' => $file, 'caption' => $caption], [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.(int) $config['photo_max_kb'], "dimensions:min_width={$minPx},min_height={$minPx}"],
            'caption' => ['nullable', 'string', 'max:100'],
        ], [], ['photo' => __('photo'), 'caption' => __('caption')])->validate();
    }

    private function store(Profile $profile, string $path, ?string $caption, int $max): Media
    {
        // Lock the profile so two parallel uploads can't both pass the limit check.
        $locked = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

        $count = Media::query()
            ->where('model_type', $locked->getMorphClass())
            ->where('model_id', $locked->getKey())
            ->where('collection_name', Profile::PHOTOS)
            ->notRejected()
            ->count();

        if ($count >= $max) {
            throw ValidationException::withMessages(['photo' => __('You can have up to :max photos. Delete one to add another.', ['max' => $max])]);
        }

        $phash = PerceptualHash::ofFile($path);

        /** @var Media $media */
        $media = $locked->addMedia($path)
            ->preservingOriginal()
            ->usingName('photo')
            ->usingFileName(Str::random(24).'.jpg')
            ->toMediaCollection(Profile::PHOTOS);

        $caption = $caption !== null ? trim($caption) : null;
        $media->forceFill([
            'moderation_status' => PhotoStatus::Pending,
            'phash' => $phash,
            'caption' => $caption === '' ? null : $caption,
        ])->save();

        // The duplicate-photo check is a scan over all photo hashes: run it once here, not on every
        // render of the A04 grid.
        $flags = array_map(fn ($flag): array => ['code' => $flag->code, 'message' => $flag->message], app(ModerationFlags::class)->forPhoto($media));

        $item = new ModerationItem;
        $item->forceFill([
            'type' => ModerationItemType::Photo,
            'profile_id' => $locked->id,
            'subject_id' => $media->uuid,
            'fields' => $flags === [] ? null : ['flags' => $flags],
            'status' => ModerationStatus::Open,
            'is_priority' => $locked->is_premium,
            'submitted_at' => now(),
        ])->save();

        $this->refreshCompleteness($locked);
        ModerationQueueChanged::dispatch(ModerationItemType::Photo);

        return $media;
    }
}
