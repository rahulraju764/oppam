<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ignore;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ignore> */
final class IgnoreFactory extends Factory
{
    protected $model = Ignore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ignorer_profile_id' => Profile::factory(),
            'ignored_profile_id' => Profile::factory(),
        ];
    }
}
