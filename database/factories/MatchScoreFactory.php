<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MatchScore;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchScore>
 */
final class MatchScoreFactory extends Factory
{
    protected $model = MatchScore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $preferenceFit = fake()->numberBetween(20, 60);
        $reverseFit = fake()->numberBetween(10, 25);
        $activity = fake()->numberBetween(4, 10);
        $completeness = fake()->numberBetween(2, 5);

        return [
            'source_profile_id' => Profile::factory(),
            'target_profile_id' => Profile::factory(),
            'score' => $preferenceFit + $reverseFit + $activity + $completeness,
            'preference_fit' => $preferenceFit,
            'reverse_fit' => $reverseFit,
            'activity_score' => $activity,
            'completeness_score' => $completeness,
            'calculated_at' => now(),
        ];
    }
}
