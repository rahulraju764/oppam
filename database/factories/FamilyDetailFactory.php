<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FamilyDetail;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilyDetail> */
final class FamilyDetailFactory extends Factory
{
    protected $model = FamilyDetail::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'father_name' => $this->faker->name('male'),
            'mother_name' => $this->faker->name('female'),
            'brothers_unmarried' => $this->faker->numberBetween(0, 2),
            'sisters_married' => $this->faker->numberBetween(0, 2),
            'native_place' => $this->faker->city(),
        ];
    }
}
