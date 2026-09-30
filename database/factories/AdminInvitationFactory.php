<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminInvitation;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An invitation whose plain token is "str_repeat('a', 64)" unless token() is used.
 *
 * @extends Factory<AdminInvitation>
 */
final class AdminInvitationFactory extends Factory
{
    protected $model = AdminInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->userName().'@oppam.test',
            'name' => $this->faker->name(),
            'role' => 'moderator',
            'token_hash' => AdminInvitation::hashToken(str_repeat('a', 64)),
            'invited_by_id' => AdminUser::factory(),
            'expires_at' => now()->addHours(72),
        ];
    }

    public function token(string $token): static
    {
        return $this->state(['token_hash' => AdminInvitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
