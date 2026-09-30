<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AdminStatus;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One demo admin per role for LOCAL development (CLAUDE.md "one admin per role"):
 * admin@oppam.test (super_admin), moderator@oppam.test, … The password comes from
 * ADMIN_SEED_PASSWORD in your local .env — never from code. Each admin enrols 2FA on first
 * sign-in, exactly like production. Never runs outside the local environment.
 */
final class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $password = (string) config('oppam.admin.seed_password');

        if ($password === '') {
            throw new RuntimeException('Set ADMIN_SEED_PASSWORD in your local .env to seed the demo admins.');
        }

        /** @var array<string, mixed> $roles */
        $roles = config('admin-permissions.roles');

        foreach (array_keys($roles) as $role) {
            $email = $role === 'super_admin' ? 'admin@oppam.test' : str_replace('_', '-', $role).'@oppam.test';

            if (AdminUser::query()->where('email', $email)->exists()) {
                continue;
            }

            $admin = new AdminUser;
            $admin->forceFill([
                'name' => 'Demo '.str($role)->headline(),
                'email' => $email,
                'password' => $password,
                'status' => AdminStatus::Active,
            ])->save();
            $admin->assignRole($role);
        }
    }
}
