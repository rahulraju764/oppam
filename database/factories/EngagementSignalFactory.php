<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Models\EngagementSignal;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EngagementSignal> */
final class EngagementSignalFactory extends Factory
{
    protected $model = EngagementSignal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'type' => EngagementSignalType::LikeSpam,
            'details' => ['likes' => 50, 'window_minutes' => 10],
            'status' => EngagementSignalStatus::Open,
            'occurrences' => 1,
            'last_raised_at' => now(),
        ];
    }

    public function on(Profile $profile, EngagementSignalType $type = EngagementSignalType::LikeSpam): self
    {
        return $this->state(['profile_id' => $profile->id, 'type' => $type]);
    }
}
