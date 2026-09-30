<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use Database\Seeders\AdminRolesSeeder;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
| Admin test helpers (oppam-testing: actingAsAdmin('perm')). Loaded from tests/Pest.php.
*/

const TEST_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function seedAdminRoles(): void
{
    test()->seed(AdminRolesSeeder::class);
}

/** An active admin with the given role; 2FA enrolled unless $twoFactor is false. */
function adminWithRole(string $role = 'super_admin', bool $twoFactor = true): AdminUser
{
    $factory = AdminUser::factory()->role($role);

    return ($twoFactor ? $factory->withTwoFactor(TEST_TOTP_SECRET) : $factory)->create();
}

/**
 * Sign an admin in the way CompleteAdminLogin does: guard user + a live registry row + the
 * "2FA passed" marker in the session. Returns the registry row.
 */
function signInAdmin(TestCase $test, AdminUser $admin, ?AdminSession $session = null): AdminSession
{
    $session ??= AdminSession::factory()->for($admin, 'admin')->create();

    $test->actingAs($admin, 'admin')->withSession([
        EnsureTwoFactorConfirmed::SESSION_KEY => now()->getTimestamp(),
        AdminSessionRegistry::SESSION_KEY => $session->id,
    ]);

    return $session;
}

function adminUrl(string $path = '/'): string
{
    return 'http://'.config('oppam.admin_domain').$path;
}

/** The current TOTP code for the shared test secret. */
function currentTotp(string $secret = TEST_TOTP_SECRET): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}
