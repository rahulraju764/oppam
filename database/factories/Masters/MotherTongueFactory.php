<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\MotherTongue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MotherTongue> */
final class MotherTongueFactory extends Factory
{
    protected $model = MotherTongue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = Str::title($this->faker->unique()->words(2, true));

        return [
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
