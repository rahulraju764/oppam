<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan migrate:fresh --seed` (LOCAL ONLY — CLAUDE.md). Reference data (masters, plans)
 * is idempotent and safe everywhere; demo members are seeded in the local environment only.
 * Settings/flags (P0.6) join this list in their session.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MastersSeeder::class,
            PlansSeeder::class,
            AdminRolesSeeder::class,
            SettingsSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call([DemoProfilesSeeder::class, AdminUsersSeeder::class]);
        }
    }
}
