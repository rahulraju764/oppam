<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployerType;
use App\Models\EducationCareer;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EducationCareer> */
final class EducationCareerFactory extends Factory
{
    protected $model = EducationCareer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'employer_type' => EmployerType::Private,
            'employer_name' => $this->faker->company(),
            'education_detail' => $this->faker->randomElement(['B.Tech, CUSAT', 'MBA, IIM Kozhikode', 'MBBS, GMC Thrissur', 'B.Sc Nursing']),
            'current_city' => $this->faker->city(),
        ];
    }
}
