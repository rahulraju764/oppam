<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Data\Broker\BiodataData;
use App\Data\Broker\PhotoFile;
use App\Enums\MaritalStatus;
use App\Livewire\Member\Profile\ProfileFacts;
use App\Models\Broker;
use App\Models\Media;
use App\Models\Profile;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;
use Illuminate\Support\Facades\Storage;

/**
 * Gathers a managed profile's export content (§11A B.7) — called by ExportManagedProfile while it
 * holds the profile's row lock. The biodata is "the same data the profile owner sees on their own
 * profile (never more)": the fact tables of the profile page (ProfileFacts) with the full name, the
 * about text, partner preferences and the primary APPROVED photo — no contact details, no
 * horoscope file. The photo package is the approved originals only.
 */
final class BiodataBuilder
{
    private const PHOTO_PX = 480;

    public function __construct(
        private readonly ProfileFacts $facts,
        private readonly Masters $masters,
    ) {}

    public function biodata(Profile $profile, Broker $broker): BiodataData
    {
        $profile->load(['educationCareer', 'familyDetail', 'lifestyleDetail', 'horoscopeDetail', 'partnerPreference']);

        $sections = $this->facts->sections($profile, null);
        $basic = __('Basic Details');
        if (isset($sections[$basic])) {
            $sections[$basic] = [__('Name') => $profile->fullName()] + $sections[$basic];
        }

        $photos = $this->approved($profile);

        return new BiodataData(
            profileCode: $profile->code,
            name: $profile->fullName(),
            photo: $photos === [] ? null : $this->photoDataUri($photos[0]),
            headline: $this->facts->headline($profile),
            sections: $sections,
            about: $this->text($profile->about),
            partner: $this->partner($profile),
            aboutPartner: $this->text($profile->partnerPreference?->about_partner),
            bureauName: $broker->displayName(),
            bureauCode: $broker->code,
            bureauPhone: $broker->phone,
            preparedOn: now((string) config('oppam.display_timezone'))->format('j M Y'),
        );
    }

    /** @return list<PhotoFile> the approved originals, primary first, named OPM…-1.jpg, -2.png … */
    public function photoFiles(Profile $profile): array
    {
        $files = [];
        foreach ($this->approved($profile) as $i => $media) {
            $extension = match ($media->mime_type) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $files[] = new PhotoFile((string) $media->disk, $media->getPathRelativeToRoot(), $profile->code.'-'.($i + 1).'.'.$extension);
        }

        return $files;
    }

    /** @return list<Media> */
    private function approved(Profile $profile): array
    {
        return array_values(Media::query()
            ->where('model_type', $profile->getMorphClass())
            ->where('model_id', $profile->getKey())
            ->where('collection_name', Profile::PHOTOS)
            ->approved()
            ->orderBy('order_column')
            ->get()->all());
    }

    /** The primary photo, scaled down and re-encoded as a JPEG data URI (dompdf reads data URIs, not our private disk). */
    private function photoDataUri(Media $media): ?string
    {
        $bytes = Storage::disk((string) $media->disk)->get($media->getPathRelativeToRoot());
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            return null;
        }

        $scaled = imagescale($image, min(self::PHOTO_PX, imagesx($image)));
        ob_start();
        imagejpeg($scaled !== false ? $scaled : $image, null, 85);
        $jpeg = (string) ob_get_clean();

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    /** @return array<string, string> */
    private function partner(Profile $profile): array
    {
        $pref = $profile->partnerPreference;
        if ($pref === null) {
            return [];
        }

        $range = fn (?string $min, ?string $max): ?string => match (true) {
            $min !== null && $max !== null => $min.' – '.$max,
            $min !== null => __('from :min', ['min' => $min]),
            $max !== null => __('up to :max', ['max' => $max]),
            default => null,
        };

        return array_filter([
            __('Age') => $range($pref->age_min !== null ? (string) $pref->age_min : null, $pref->age_max !== null ? __(':age years', ['age' => $pref->age_max]) : null),
            __('Height') => $range(
                $pref->height_min_cm !== null ? HeightCm::of($pref->height_min_cm)->label() : null,
                $pref->height_max_cm !== null ? HeightCm::of($pref->height_max_cm)->label() : null,
            ),
            __('Marital Status') => $this->joined(array_map(
                fn (string $value): ?string => MaritalStatus::tryFrom($value)?->label(), $pref->marital_statuses ?? [])),
            __('Religion') => $this->labels($this->masters->religions(), $pref->religion_ids ?? []),
            __('Mother Tongue') => $this->labels($this->masters->motherTongues(), $pref->mother_tongue_ids ?? []),
            __('Education') => $this->labels($this->masters->education(), $pref->education_ids ?? []),
            __('Occupation') => $this->labels($this->masters->occupations(), $pref->occupation_ids ?? []),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<\App\Data\Masters\MasterItem>  $items
     * @param  list<int>  $ids
     */
    private function labels(array $items, array $ids): ?string
    {
        $options = Masters::forSelect($items);

        return $this->joined(array_map(fn (int $id): ?string => $options[$id] ?? null, $ids));
    }

    /** @param  list<string|null>  $values */
    private function joined(array $values): ?string
    {
        $values = array_values(array_filter($values, fn (?string $v): bool => $v !== null && $v !== ''));

        return $values === [] ? null : implode(', ', $values);
    }

    private function text(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
