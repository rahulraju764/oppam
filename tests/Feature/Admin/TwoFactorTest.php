<?php

declare(strict_types=1);

use App\Actions\Admin\Auth\EnrolAdminTwoFactor;
use App\Actions\Admin\Auth\VerifyAdminSecondFactor;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Livewire\Admin\Auth\TwoFactorChallenge;
use App\Livewire\Admin\Auth\TwoFactorSetup;
use App\Models\AdminSession;
use App\Models\AuditLog;
use App\Notifications\Admin\AdminAccountLocked;
use App\Services\Admin\PendingAdminLogin;
use App\Services\Admin\TwoFactorAuthenticator;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P0.5 — mandatory TOTP 2FA for admins (PRD A01, §8.4): enrolment on first login, the challenge,
| recovery codes, 5-strike lock + alert, replay refusal.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

it('enrols 2FA on first login and shows 10 single-use recovery codes once', function (): void {
    $admin = adminWithRole('moderator', twoFactor: false);
    $enrol = app(EnrolAdminTwoFactor::class);

    $secret = $enrol->begin($admin);
    $codes = $enrol->confirm($admin->fresh(), currentTotp($secret));

    $admin->refresh();
    expect($admin->hasConfirmedTwoFactor())->toBeTrue()
        ->and($codes)->toHaveCount(10)
        ->and($admin->two_factor_recovery_codes)->toHaveCount(10)
        ->and($admin->two_factor_recovery_codes)->not->toContain($codes[0])            // stored hashed
        ->and($admin->getAttributes()['two_factor_secret'])->not->toBe($secret);        // encrypted at rest
});

it('refuses to confirm enrolment with a wrong code', function (): void {
    $admin = adminWithRole('moderator', twoFactor: false);
    app(EnrolAdminTwoFactor::class)->begin($admin);

    expect(fn () => app(EnrolAdminTwoFactor::class)->confirm($admin->fresh(), '000000'))
        ->toThrow(AdminAuthenticationFailed::class);
    expect($admin->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('completes sign-in with a valid code: session registered, 2FA marked, audited', function (): void {
    $admin = adminWithRole('moderator');
    app(PendingAdminLogin::class)->start($admin);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', currentTotp())
        ->call('verify')
        ->assertRedirect(route('admin.dashboard'));

    expect(auth('admin')->id())->toBe($admin->id)
        ->and(AdminSession::query()->where('admin_user_id', $admin->id)->live()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'admin.login')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('refuses the same TOTP code twice (replay)', function (): void {
    $twoFactor = app(TwoFactorAuthenticator::class);
    $code = currentTotp();

    expect($twoFactor->verify(TEST_TOTP_SECRET, $code))->toBeTrue()
        ->and($twoFactor->verify(TEST_TOTP_SECRET, $code))->toBeFalse();
});

it('accepts a recovery code exactly once', function (): void {
    $admin = adminWithRole('moderator');   // factory codes: recover-code1, recover-code2
    $verify = app(VerifyAdminSecondFactor::class);

    $verify->handle($admin, 'recover-code1', isRecoveryCode: true);

    expect(fn () => $verify->handle($admin->fresh(), 'recover-code1', isRecoveryCode: true))
        ->toThrow(AdminAuthenticationFailed::class);
    expect($admin->fresh()->two_factor_recovery_codes)->toHaveCount(1);
});

it('locks the account after 5 wrong codes and alerts the other super admins (A01)', function (): void {
    Notification::fake();
    $otherSuperAdmin = adminWithRole('super_admin');
    $admin = adminWithRole('moderator');
    $verify = app(VerifyAdminSecondFactor::class);

    foreach (range(1, 4) as $attempt) {
        expect(fn () => $verify->handle($admin->fresh(), '000000'))->toThrow(AdminAuthenticationFailed::class, 'not valid');
    }
    expect(fn () => $verify->handle($admin->fresh(), '000000'))->toThrow(AdminAuthenticationFailed::class, 'locked');

    expect($admin->fresh()->isLocked())->toBeTrue()
        // A locked account refuses even the right code.
        ->and(fn () => $verify->handle($admin->fresh(), currentTotp()))->toThrow(AdminAuthenticationFailed::class, 'locked');

    Notification::assertSentTo($otherSuperAdmin, AdminAccountLocked::class, fn ($n) => $n->lockedEmail === $admin->email);
    expect(AuditLog::query()->where('action', 'admin.locked')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

it('sends the challenge and setup pages back to the login without a pending sign-in', function (string $component): void {
    Livewire::test($component)->assertRedirect(route('admin.login'));
})->with([
    'challenge' => [TwoFactorChallenge::class],
    'setup' => [TwoFactorSetup::class],
]);

it('expires a pending sign-in after 10 minutes', function (): void {
    $admin = adminWithRole('moderator');
    app(PendingAdminLogin::class)->start($admin);

    $this->travel(11)->minutes();

    expect(app(PendingAdminLogin::class)->admin())->toBeNull();
});

it('walks the setup screen: QR code, confirm, recovery codes, then into the panel', function (): void {
    $admin = adminWithRole('moderator', twoFactor: false);
    app(PendingAdminLogin::class)->start($admin);

    $component = Livewire::test(TwoFactorSetup::class)
        ->assertSeeHtml('data:image/svg+xml;base64,');

    $secret = $admin->fresh()->two_factor_secret;

    $component->set('code', currentTotp($secret))
        ->call('confirm')
        ->assertSee('Save your recovery codes')
        ->call('finish')
        ->assertRedirect(route('admin.dashboard'));

    expect(auth('admin')->id())->toBe($admin->id);
});
