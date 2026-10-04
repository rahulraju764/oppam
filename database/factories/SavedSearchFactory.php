<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertFrequency;
use App\Models\Profile;
use App\Models\SavedSearch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
final class SavedSearchFactory extends Factory
{
    protected $model = SavedSearch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'name' => fake()->words(3, true),
            'filters' => [
                'age_min' => 24,
                'age_max' => 30,
            ],
            'alert_frequency' => AlertFrequency::Daily,
            'last_alerted_at' => null,
        ];
    }

    public function weekly(): self
    {
        return $this->state(['alert_frequency' => AlertFrequency::Weekly]);
    }

    public function off(): self
    {
        return $this->state(['alert_frequency' => AlertFrequency::Off]);
    }
}
