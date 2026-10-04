<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImpersonationSession> */
final class ImpersonationSessionFactory extends Factory
{
    protected $model = ImpersonationSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory(),
            'admin_label' => 'admin@oppam.test',
            'user_id' => User::factory(),
            'reason' => 'Member asked for help with the wizard.',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
