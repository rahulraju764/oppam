<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use App\Services\Media\ImageSanitizer;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Upload (or replace) the horoscope (M02 step 6, M11): PDF / JPG / PNG ≤ 5 MB, kept on the
 * private disk under a random name, only ever served through HoroscopeController's signed and
 * audited route. Shares the member's hourly upload limit with photos.
 */
final class UploadHoroscope
{
    use ManagesOwnPhotos;

    private const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ImageSanitizer $sanitizer,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, UploadedFile $file): Media
    {
        $this->authorizePhotos($actor, $profile);

        Validator::make(['horoscope' => $file], [
            'horoscope' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png',
                'max:'.(int) config('oppam.media.horoscope_max_kb')],
        ], [], ['horoscope' => __('horoscope')])->validate();

        // Sniff the bytes ourselves: the browser's (or a tampered request's) claimed type is not proof.
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath());
        if (! in_array($sniffed, self::ALLOWED, true)) {
            throw ValidationException::withMessages(['horoscope' => __('Please upload the horoscope as a PDF, JPG or PNG file.')]);
        }

        $key = 'media-upload:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, (int) config('oppam.media.uploads_per_hour'))) {
            throw ValidationException::withMessages(['horoscope' => __('You have uploaded a lot of files. Please try again in an hour.')]);
        }

        // Images are re-encoded like photos (EXIF / GPS dropped); PDFs are stored as sent.
        $path = (string) $file->getRealPath();
        $clean = null;
        if ($sniffed !== 'application/pdf') {
            try {
                $clean = $this->sanitizer->sanitize($path)['path'];
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['horoscope' => __('Please upload the horoscope as a PDF, JPG or PNG file.')]);
            }
        }

        try {
            /** @var Media $media */
            $media = $profile->addMedia($clean ?? $path)
                ->preservingOriginal()
                ->usingName('horoscope')
                ->usingFileName(Str::random(24).($clean === null ? '.pdf' : '.jpg'))
                ->toMediaCollection(Profile::HOROSCOPE);
        } finally {
            if ($clean !== null) {
                @unlink($clean);
            }
        }

        $this->limiter->hit($key, 3600);

        return $media;
    }
}
