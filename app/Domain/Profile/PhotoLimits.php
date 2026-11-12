<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;

/**
 * Profile photo limits (M02 step 6 / M11), from the A15 business rules (P8.4) so an admin can change
 * them without a deploy: the main photo + `photos.max_additional` more, each up to
 * `photos.max_upload_mb`. Used by the upload Action and both photo screens (member + bureau).
 */
final class PhotoLimits
{
    /** All photos a profile may hold, the main one included. */
    public static function maxPhotos(): int
    {
        return Settings::int(SettingKey::PhotosMaxAdditional) + 1;
    }

    public static function maxKilobytes(): int
    {
        return Settings::int(SettingKey::PhotosMaxUploadMb) * 1024;
    }
}
