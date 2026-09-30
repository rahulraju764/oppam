<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\District;
use App\Models\Masters\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<District> */
final class DistrictFactory extends Factory
{
    protected $model = District::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = Str::title($this->faker->unique()->words(2, true));

        return [
            'state_id' => State::factory(),
            'code' => Str::upper(Str::snake($label)).'_'.$this->faker->unique()->numberBetween(1, 999999),
            'label' => $label,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
