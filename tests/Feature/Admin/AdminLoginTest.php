<?php

declare(strict_types=1);

use App\Actions\Admin\Auth\AttemptAdminLogin;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Models\AdminUser;
use App\Models\AuditLog;

/*
| P0.5 — admin sign-in step 1 (PRD A01): generic errors, 3-strike lockout per email (known or not),
| suspended/locked accounts refused. Step 2 (2FA) is in TwoFactorTest.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

function attemptLogin(string $email, string $password = 'Password-1234'): AdminUser
{
    return app(AttemptAdminLogin::class)->handle($email, $password, '10.0.0.1');
}

it('accepts a correct email and password without signing the admin in yet', function (): void {
    $admin = adminWithRole('moderator');

    expect(attemptLogin(strtoupper($admin->email))->is($admin))->toBeTrue()
        ->and(auth('admin')->check())->toBeFalse();   // signed in only after 2FA
});

it('gives the same answer for a wrong password and an unknown email (no enumeration)', function (): void {
    $admin = adminWithRole('moderator');

    $wrongPassword = rescue(fn () => attemptLogin($admin->email, 'nope'), fn (Throwable $e) => $e->getMessage(), false);
    $unknownEmail = rescue(fn () => attemptLogin('nobody@oppam.test'), fn (Throwable $e) => $e->getMessage(), false);

    expect($wrongPassword)->toBe($unknownEmail)
        ->and($wrongPassword)->toBe(AdminAuthenticationFailed::invalidCredentials()->getMessage());
});

it('locks an email for 30 minutes after 3 failed attempts, even with the right password next (A01)', function (): void {
    $admin = adminWithRole('moderator');

    foreach (range(1, 3) as $attempt) {
        rescue(fn () => attemptLogin($admin->email, 'wrong-'.$attempt), report: false);
    }

    expect(fn () => attemptLogin($admin->email))->toThrow(AdminAuthenticationFailed::class, 'Too many attempts');

    $this->travel(31)->minutes();
    expect(attemptLogin($admin->email)->is($admin))->toBeTrue();
});

it('locks unknown emails exactly the same way (no enumeration through the lockout)', function (): void {
    foreach (range(1, 3) as $attempt) {
        rescue(fn () => attemptLogin('ghost@oppam.test', 'x'), report: false);
    }

    expect(fn () => attemptLogin('ghost@oppam.test'))->toThrow(AdminAuthenticationFailed::class, 'Too many attempts');
});

it('refuses suspended and 2FA-locked admins with the generic message', function (string $state): void {
    $admin = AdminUser::factory()->{$state}()->role('moderator')->create();

    expect(fn () => attemptLogin($admin->email))
        ->toThrow(AdminAuthenticationFailed::class, AdminAuthenticationFailed::invalidCredentials()->getMessage());
})->with(['suspended', 'locked']);

it('audits failed sign-ins for real admin accounts', function (): void {
    $admin = adminWithRole('moderator');

    rescue(fn () => attemptLogin($admin->email, 'wrong'), report: false);

    expect(AuditLog::query()->where('action', 'admin.login_failed')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

it('sends the admin to 2FA enrolment or the 2FA challenge after the password step', function (bool $enrolled, string $route): void {
    $admin = adminWithRole('moderator', twoFactor: $enrolled);

    Livewire\Livewire::test(App\Livewire\Admin\Auth\Login::class)
        ->set('email', $admin->email)
        ->set('password', 'Password-1234')
        ->call('login')
        ->assertRedirect(route($route));
})->with([
    'not enrolled yet' => [false, 'admin.two-factor.setup'],
    'enrolled' => [true, 'admin.two-factor.challenge'],
]);

it('shows the generic error on the login form and clears the password', function (): void {
    Livewire\Livewire::test(App\Livewire\Admin\Auth\Login::class)
        ->set('email', 'nobody@oppam.test')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSet('password', '');
});
