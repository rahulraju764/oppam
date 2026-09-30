<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Database\Seeders\AdminRolesSeeder;
use Laravel\Dusk\Browser;
use PragmaRX\Google2FA\Google2FA;

/*
| Dusk admin helpers (tests/Browser/Admin, tests/Browser/Mobile). They run against the LOCAL dev
| server and database: a throwaway dusk-admin@oppam.test account with a RANDOM password is created
| for each test and deleted afterwards (duskAdminCleanup, registered in tests/Pest.php). Only its
| own rate-limiter keys are cleared. Its audit rows stay (audit_logs is append-only).
| Loaded from tests/Pest.php.
*/

const DUSK_ADMIN_EMAIL = 'dusk-admin@oppam.test';

function duskAdminPassword(): string
{
    static $password = null;

    return $password ??= 'Dusk-'.Illuminate\Support\Str::password(24, symbols: false).'-1a';
}

function duskAdmin(): AdminUser
{
    (new AdminRolesSeeder)->run(app(Spatie\Permission\PermissionRegistrar::class));

    duskAdminCleanup();

    $admin = new AdminUser;
    $admin->forceFill(['name' => 'Dusk Admin', 'email' => DUSK_ADMIN_EMAIL, 'password' => duskAdminPassword()])->save();
    $admin->assignRole('super_admin');

    return $admin;
}

/** Remove the throwaway account (sessions cascade) and its rate-limiter keys. */
function duskAdminCleanup(): void
{
    $admin = AdminUser::query()->firstWhere('email', DUSK_ADMIN_EMAIL);

    Illuminate\Support\Facades\RateLimiter::clear('admin-login:email:'.sha1(DUSK_ADMIN_EMAIL));
    Illuminate\Support\Facades\RateLimiter::clear('admin-login:ip:127.0.0.1');

    if ($admin !== null) {
        Illuminate\Support\Facades\RateLimiter::clear('admin-2fa:'.$admin->id);
        $admin->syncRoles([]);
        $admin->delete();
    }
}

function adminDuskUrl(string $path): string
{
    return 'http://'.config('oppam.admin_domain').':'.parse_url(config('app.url'), PHP_URL_PORT).$path;
}

/** Sign the Dusk admin in through the real password + 2FA-enrolment screens. */
function duskSignInAdmin(Browser $browser): void
{
    duskAdmin();

    $browser->visit(adminDuskUrl('/login'))
        ->type('#f-email', DUSK_ADMIN_EMAIL)
        ->type('#f-password', duskAdminPassword())
        ->press('Continue')
        ->waitForLocation('/two-factor/setup');

    $code = app(Google2FA::class)->getCurrentOtp(str_replace(' ', '', $browser->text('.mono-key')));

    $browser->type('#f-code', $code)
        ->press('Confirm')
        ->waitForText('Save your recovery codes')
        ->press('I have saved them — continue')
        ->waitForLocation('/');
}
