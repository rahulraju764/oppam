<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Data\Moderation\ModerationFlag;
use App\Enums\Gender;
use App\Enums\SettingKey;
use App\Models\Media;
use App\Models\Profile;
use App\Services\Settings\SettingsRepository;

/**
 * Automatic pre-flags for A04 (PRD §11 A04): contact details in text, profanity (EN + ML word
 * list, config/moderation.php), underage, the same name + date of birth on another profile, and
 * a photo that is a near-duplicate (perceptual hash) of another profile's photo. Flags guide the
 * moderator; only underage leads to an automatic rejection (AutoRejectUnderage).
 */
final class ModerationFlags
{
    private const PHONE = '/(?:\+?\d[\s.\-()]*){10,}/u';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';

    private const HANDLES = '/\b(whats\s?app|telegram|insta(gram)?|facebook|fb\.com|signal|call\s+me|wa\.me)\b/iu';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Flags for a profile's own text and identity.
     *
     * @param  array<string, string|null>  $texts  label => text to scan (about, names, captions …)
     * @return list<ModerationFlag>
     */
    public function forProfile(Profile $profile, array $texts): array
    {
        $flags = [...$this->forTexts($texts)];

        if ($this->isUnderage($profile)) {
            $flags[] = new ModerationFlag('underage', __('Under the legal marriage age.'));
        }

        $twin = $this->sameNameAndDob($profile);
        if ($twin !== null) {
            $flags[] = new ModerationFlag('duplicate_identity', __('Same name and date of birth as :code.', ['code' => $twin]));
        }

        return $flags;
    }

    /**
     * Flags for texts alone (edited fields, captions).
     *
     * @param  array<string, string|null>  $texts
     * @return list<ModerationFlag>
     */
    public function forTexts(array $texts): array
    {
        $flags = [];

        foreach ($texts as $label => $text) {
            if ($text === null || trim($text) === '') {
                continue;
            }

            if (preg_match(self::PHONE, $text) === 1 || preg_match(self::EMAIL, $text) === 1 || preg_match(self::HANDLES, $text) === 1) {
                $flags[] = new ModerationFlag('contact_info', __('Possible contact details in :field.', ['field' => $label]));
            }

            $word = $this->profanity($text);
            if ($word !== null) {
                $flags[] = new ModerationFlag('profanity', __('Possible abusive word in :field.', ['field' => $label]));
            }
        }

        return $flags;
    }

    /**
     * A photo that is a near-duplicate of a photo on ANOTHER profile.
     *
     * @return list<ModerationFlag>
     */
    public function forPhoto(Media $photo): array
    {
        if ($photo->phash === null) {
            return [];
        }

        $distance = (int) config('moderation.duplicate_photo_distance');

        // 64-bit Hamming distance in the database: BIT_COUNT(a XOR b) over the hex hashes.
        $twin = Media::query()
            ->where('collection_name', Profile::PHOTOS)
            ->where('model_id', '!=', $photo->model_id)
            ->whereNotNull('phash')
            ->whereRaw('BIT_COUNT(CAST(CONV(phash, 16, 10) AS UNSIGNED) ^ CAST(CONV(?, 16, 10) AS UNSIGNED)) <= ?', [$photo->phash, $distance])
            ->value('model_id');

        if ($twin === null) {
            return [];
        }

        $code = Profile::withTrashed()->whereKey($twin)->value('code');

        return [new ModerationFlag('duplicate_photo', __('Same photo as on :code.', ['code' => $code ?? '—']))];
    }

    public function isUnderage(Profile $profile): bool
    {
        $age = $profile->age();

        if ($age === null) {
            return false;
        }

        $minimum = $this->settings->int($profile->gender === Gender::Female ? SettingKey::MinAgeFemale : SettingKey::MinAgeMale);

        return $age < $minimum;
    }

    private function sameNameAndDob(Profile $profile): ?string
    {
        if ($profile->dob === null || $profile->last_name === null) {
            return null;
        }

        $code = Profile::query()
            ->whereKeyNot($profile->id)
            ->where('dob', $profile->dob->toDateString())
            // utf8mb4_unicode_ci compares case-insensitively; plain columns keep the index usable.
            ->where('last_name', $profile->last_name)
            ->where('first_name', $profile->first_name)
            ->value('code');

        return is_string($code) ? $code : null;
    }

    private function profanity(string $text): ?string
    {
        $lower = mb_strtolower($text);
        /** @var array<string, list<string>> $lists */
        $lists = (array) config('moderation.profanity');

        foreach ($lists as $words) {
            foreach ($words as $word) {
                // Whole words only (Unicode-aware), so "Scunthorpe"-style substrings don't trip it.
                if (preg_match('/(?<![\p{L}\p{M}])'.preg_quote(mb_strtolower($word), '/').'(?![\p{L}\p{M}])/u', $lower) === 1) {
                    return $word;
                }
            }
        }

        return null;
    }
}
