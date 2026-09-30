<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AdminStatus;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Database\Seeders\AdminRolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the FIRST super admin on a fresh environment (production has no demo admins — the
 * seeder is local-only). Everyone after that is invited from the panel (A01). The password is
 * prompted, never passed on the command line (shell history); 2FA is enrolled at first sign-in.
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'oppam:create-super-admin';

    protected $description = 'Create the first super admin (prompts for name, email and password)';

    public function handle(AuditLogger $audit): int
    {
        $this->callSilent('db:seed', ['--class' => AdminRolesSeeder::class, '--force' => true]);

        $name = text('Name', required: true);
        $email = Str::lower(trim(text('Work email', required: true)));
        $secret = password('Password (12+ characters, mixed case, a number)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $secret],
            ['email' => ['required', 'email', 'unique:admin_users,email'], 'password' => ['required', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = new AdminUser;
        $admin->forceFill(['name' => $name, 'email' => $email, 'password' => $secret, 'status' => AdminStatus::Active])->save();
        $admin->assignRole('super_admin');

        $audit->record('staff.super_admin_created_cli', $admin, after: ['email' => $email], subjectLabel: $email);

        $this->info("Super admin {$email} created. Sign in at the admin domain to set up two-factor authentication.");

        return self::SUCCESS;
    }
}
