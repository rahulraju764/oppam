<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyMatch;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyMatch>
 */
final class DailyMatchFactory extends Factory
{
    protected $model = DailyMatch::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'matched_profile_id' => Profile::factory(),
            'match_date' => CarbonImmutable::now('Asia/Kolkata')->toDateString(),
            'score' => fake()->numberBetween(60, 98),
            'is_viewed' => false,
            'is_interacted' => false,
        ];
    }

    public function viewed(): self
    {
        return $this->state(['is_viewed' => true]);
    }

    public function interacted(): self
    {
        return $this->state(['is_interacted' => true]);
    }
}
