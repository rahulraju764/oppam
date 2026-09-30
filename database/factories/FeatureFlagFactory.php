<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeatureFlag> */
final class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'likes.enabled',
            'is_enabled' => false,
        ];
    }
}
