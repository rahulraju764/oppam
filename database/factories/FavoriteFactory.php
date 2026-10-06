<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Favorite;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Favorite> */
final class FavoriteFactory extends Factory
{
    protected $model = Favorite::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'favorite_profile_id' => Profile::factory(),
        ];
    }

    public function between(Profile $owner, Profile $favorite): self
    {
        return $this->state(['profile_id' => $owner->id, 'favorite_profile_id' => $favorite->id]);
    }
}
