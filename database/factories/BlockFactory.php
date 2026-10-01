<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Block;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Block> */
final class BlockFactory extends Factory
{
    protected $model = Block::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'blocker_profile_id' => Profile::factory(),
            'blocked_profile_id' => Profile::factory(),
        ];
    }
}
