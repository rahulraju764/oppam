<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationPreference> */
final class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => 'interest_received',
            'in_app' => true,
            'email' => true,
            'sms' => false,
            'push' => false,
        ];
    }
}
