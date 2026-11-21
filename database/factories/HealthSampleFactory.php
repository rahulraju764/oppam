<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HealthMetric;
use App\Models\HealthSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Reverb connection-count sample.
 *
 * @extends Factory<HealthSample>
 */
final class HealthSampleFactory extends Factory
{
    protected $model = HealthSample::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'metric' => HealthMetric::ReverbConnections,
            'value' => 120,
            'sampled_at' => now(),
        ];
    }
}
