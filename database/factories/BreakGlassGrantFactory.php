<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BreakGlassReference;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BreakGlassGrant> */
final class BreakGlassGrantFactory extends Factory
{
    protected $model = BreakGlassGrant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory(),
            'conversation_id' => Conversation::factory(),
            'reason' => 'Police request about a reported fraud',
            'reference_type' => BreakGlassReference::Legal,
            'reference' => 'KL/POL/2026/123',
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
