<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanPrice> */
final class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'duration_months' => 1,
            'price_paise' => 99900,
            'is_active' => true,
        ];
    }
}
