<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreatedFor;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fake members only (CLAUDE.md: seeders use fake data). Mobile numbers use the +91 90000 xxxxx
 * block, which the demo data never shares with a real person's number range in tests.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'phone' => '+9190000'.$this->faker->unique()->numerify('#####'),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'created_for' => CreatedFor::Self,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
            'phone_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['phone_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => UserStatus::Suspended]);
    }

    public function banned(): static
    {
        return $this->state(['status' => UserStatus::Banned]);
    }

    public function broker(): static
    {
        return $this->state(['role' => UserRole::Broker]);
    }

    public function createdFor(CreatedFor $createdFor): static
    {
        return $this->state(['created_for' => $createdFor]);
    }
}
