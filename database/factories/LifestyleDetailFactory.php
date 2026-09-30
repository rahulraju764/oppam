<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LifestyleDetail;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LifestyleDetail> */
final class LifestyleDetailFactory extends Factory
{
    protected $model = LifestyleDetail::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'hobbies' => $this->faker->randomElements(['Music', 'Reading', 'Travel', 'Cooking', 'Cricket', 'Photography'], 2),
        ];
    }
}
