<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PhotoVisibility;
use App\Models\PrivacySetting;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrivacySetting> */
final class PrivacySettingFactory extends Factory
{
    protected $model = PrivacySetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'photo_visibility' => PhotoVisibility::AllMembers,
        ];
    }
}
