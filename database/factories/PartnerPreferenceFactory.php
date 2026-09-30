<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PartnerPreference;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PartnerPreference> */
final class PartnerPreferenceFactory extends Factory
{
    protected $model = PartnerPreference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'age_min' => 22,
            'age_max' => 32,
            'height_min_cm' => 150,
            'height_max_cm' => 185,
            'marital_statuses' => ['NEVER_MARRIED'],
            'religion_ids' => [],
            'caste_ids' => [],
        ];
    }
}
