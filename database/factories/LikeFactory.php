<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Like;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Like> */
final class LikeFactory extends Factory
{
    protected $model = Like::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'from_profile_id' => Profile::factory(),
            'to_profile_id' => Profile::factory(),
        ];
    }

    public function between(Profile $from, Profile $to): self
    {
        return $this->state(['from_profile_id' => $from->id, 'to_profile_id' => $to->id]);
    }

    public function seen(): self
    {
        return $this->state(['seen_at' => now()]);
    }
}
