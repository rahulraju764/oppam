<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminSession> */
final class AdminSessionFactory extends Factory
{
    protected $model = AdminSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'admin_user_id' => AdminUser::factory(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test browser',
            'last_seen_at' => now(),
        ];
    }
}
