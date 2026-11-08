<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClosureReason;
use App\Models\ProfileClosure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfileClosure> */
final class ProfileClosureFactory extends Factory
{
    protected $model = ProfileClosure::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'profile_id' => null,
            'reason' => ClosureReason::NotInterested,
            'feedback' => null,
            'created_at' => now(),
            'restored_at' => null,
        ];
    }
}
