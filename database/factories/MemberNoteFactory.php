<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MemberNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberNote> */
final class MemberNoteFactory extends Factory
{
    protected $model = MemberNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'admin_label' => 'admin@oppam.test',
            'body' => $this->faker->sentence(12),
        ];
    }
}
