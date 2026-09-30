<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Flag;
use App\Enums\SettingKey;
use App\Models\FeatureFlag;
use App\Models\Setting;
use App\Services\Settings\FeatureFlags;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Every A15 setting with its PRD default and every feature flag (OFF). INSERT-ONLY: an existing
 * row is never touched, so a re-seed can't undo an admin's change. Safe in every environment.
 *
 * seo.indexable is NOT seeded: until an admin sets it in A15, it follows APP_INDEXABLE, so the
 * launch runbook's env flip keeps working (a seeded row would freeze the seed-time value).
 */
final class SettingsSeeder extends Seeder
{
    public function run(SettingsRepository $settings, FeatureFlags $flags): void
    {
        foreach (SettingKey::cases() as $key) {
            if ($key === SettingKey::SeoIndexable) {
                continue;
            }

            if (! Setting::query()->whereKey($key->value)->exists()) {
                (new Setting)->forceFill(['key' => $key->value, 'value' => $key->default()])->save();
            }
        }

        foreach (Flag::cases() as $flag) {
            if (! FeatureFlag::query()->whereKey($flag->value)->exists()) {
                (new FeatureFlag)->forceFill(['key' => $flag->value, 'is_enabled' => false])->save();
            }
        }

        $settings->flush();
        $flags->flush();
    }
}
