<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => PlanCode::Gold,
            'name' => 'Gold',
            'is_featured' => false,
            'is_purchasable' => true,
            'is_active' => true,
            'display_features' => ['Send 100 interests a month'],
            'sort_order' => 1,
        ];
    }

    public function code(PlanCode $code): static
    {
        return $this->state(['code' => $code, 'name' => $code->label(), 'is_purchasable' => $code !== PlanCode::Free]);
    }
}
