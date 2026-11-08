<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailChangeRequest> */
final class EmailChangeRequestFactory extends Factory
{
    protected $model = EmailChangeRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'expires_at' => now()->addDay(),
            'consumed_at' => null,
        ];
    }

    /** A link for this token (the hash is stored, never the token). */
    public function forToken(string $token): static
    {
        return $this->state(['token_hash' => hash('sha256', $token)]);
    }
}
