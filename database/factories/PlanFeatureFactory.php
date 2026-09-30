<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Entitlement;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanFeature> */
final class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'entitlement' => Entitlement::InterestsPerMonth,
            'limit_value' => 25,
            'is_enabled' => true,
        ];
    }
}
