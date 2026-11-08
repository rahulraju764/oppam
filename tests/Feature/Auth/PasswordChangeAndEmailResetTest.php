<?php

declare(strict_types=1);

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\LogoutOtherDevices;
use App\Actions\Auth\ResetPasswordByEmail;
use App\Actions\Auth\SendPasswordResetLink;
use App\Actions\Auth\SignInMember;
use App\Enums\UserStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\LoginFailed;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Notifications\Auth\PasswordResetLink;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| P8.2b — change password (M14 / M01) and forgot password by email link (PRD §8.2 method 3; owner
| decisions 2026-11-08): the current password is checked and rate-limited; a change signs every
| other device out and keeps this one; a reset link is mailed only to a verified member address,
| with the same answer for any address (R-M01-4), single-use, 60 minutes, and ends every session.
| Log out other devices now also closes other devices' tabs live (M01 ForceLogout).
*/

beforeEach(function (): void {
    fakeSms();
    Notification::fake();
    Event::fake([ForceLogout::class]);
});

function pwMember(bool $verifiedEmail = true): User
{
    $member = memberWithPhone('+919876533001');
    $member->forceFill(['email' => 'pw@example.com', 'email_verified_at' => $verifiedEmail ? now() : null])->save();

    return $member->refresh();
}

function pwMailedToken(): ?string
{
    $token = null;
    Notification::assertSentOnDemand(PasswordResetLink::class, function (PasswordResetLink $n) use (&$token): bool {
        $token = basename($n->url);

        return true;
    });

    return $token;
}

// ---- Change password ---------------------------------------------------------------------------

it('R-M14: the right current password changes it; other devices out, this one kept, notice + audit', function (): void {
    $member = pwMember();
    $request = requestWithSession();
    $epoch = $member->session_epoch;

    app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', $request);

    $member->refresh();
    expect(Hash::check('NewPass2026', $member->password))->toBeTrue()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and(session(SignInMember::EPOCH_SESSION_KEY))->toBe($epoch + 1);
    Event::assertDispatched(ForceLogout::class);
    Notification::assertSentTo($member, AccountSecurityChanged::class);
    expect(AuditLog::query()->where('action', 'account.password_changed')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('action', 'account.password_changed')->sole()->toArray()))->not->toContain('NewPass2026');
});

it('a wrong current password is refused and, after 5 tries, locked for a while — nothing changes', function (): void {
    $member = pwMember();
    requestWithSession();

    for ($i = 0; $i < 5; $i++) {
        expect(fn () => app(ChangePassword::class)->handle($member, 'wrong-one-1', 'NewPass2026', request()))
            ->toThrow(AccountChangeRefused::class, 'isn’t right');
    }
    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', request()))
        ->toThrow(AccountChangeRefused::class, 'Too many tries');
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->refresh()->password))->toBeTrue();
    Event::assertNotDispatched(ForceLogout::class);
});

it('the new password must differ from the current one', function (): void {
    $member = pwMember();
    requestWithSession();

    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, TEST_MEMBER_PASSWORD, request()))
        ->toThrow(AccountChangeRefused::class, 'different');
});

it('change password is refused in an admin support session', function (): void {
    $member = pwMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', requestWithSession()))
        ->toThrow(ImpersonationRestricted::class);
});

it('M01: log out other devices now also tells every open tab to leave (ForceLogout after commit)', function (): void {
    $member = pwMember();

    app(LogoutOtherDevices::class)->handle($member, requestWithSession());

    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === (string) $member->id && $e->broadcastWith() === ['scope' => ForceLogout::ALL]);
});

// ---- Forgot password by email -----------------------------------------------------------------------

it('§8.2 / R-M01-4: a link goes only to a verified member address; any other address gets the same answer and no mail', function (string $case): void {
    $member = pwMember($case !== 'unverified');
    if ($case === 'suspended') {
        $member->forceFill(['status' => UserStatus::Suspended])->save();
    }
    $address = $case === 'unknown' ? 'nobody@example.com' : 'pw@example.com';

    app(SendPasswordResetLink::class)->handle($address, '10.1.1.1');   // never throws for an unknown address

    if ($case === 'verified') {
        Notification::assertSentOnDemand(PasswordResetLink::class, fn ($n, array $c, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'pw@example.com');
        expect(DB::table('password_reset_tokens')->where('email', 'pw@example.com')->value('token'))->not->toBe(pwMailedToken());   // stored hashed
    } else {
        Notification::assertSentOnDemandTimes(PasswordResetLink::class, 0);
    }
})->with(['verified', 'unverified', 'unknown', 'suspended']);

it('the link resets the password once, ends every session, signs in here and notifies', function (): void {
    $member = pwMember();
    $epoch = $member->session_epoch;
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $token = (string) pwMailedToken();

    app(ResetPasswordByEmail::class)->handle($token, 'FreshPass2026', requestWithSession());

    $member->refresh();
    expect(Hash::check('FreshPass2026', $member->password))->toBeTrue()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and(auth('web')->id())->toBe($member->id);
    Event::assertDispatched(ForceLogout::class);
    Notification::assertSentTo($member, AccountSecurityChanged::class);

    expect(fn () => app(ResetPasswordByEmail::class)->handle($token, 'OtherPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);   // single use
});

it('an expired, unknown or replaced link is refused and changes nothing', function (): void {
    $member = pwMember();
    expect(fn () => app(ResetPasswordByEmail::class)->handle(str_repeat('x', 64), 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);

    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $first = (string) pwMailedToken();
    Notification::fake();
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    expect(fn () => app(ResetPasswordByEmail::class)->handle($first, 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);   // replaced by the newer link

    $second = (string) pwMailedToken();
    $this->travel((int) config('oppam.auth.reset_link_minutes') + 1)->minutes();
    expect(fn () => app(ResetPasswordByEmail::class)->handle($second, 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->refresh()->password))->toBeTrue();
});

it('a link for an account suspended since is refused with the blocked message', function (): void {
    $member = pwMember();
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $member->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => app(ResetPasswordByEmail::class)->handle((string) pwMailedToken(), 'FreshPass2026', requestWithSession()))
        ->toThrow(LoginFailed::class);
});

it('rule 10: reset-link requests are limited per address and per IP, known address or not', function (): void {
    for ($i = 0; $i < (int) config('oppam.auth.reset_links_per_hour'); $i++) {
        app(SendPasswordResetLink::class)->handle('nobody@example.com', '10.1.1.'.$i);
    }
    expect(fn () => app(SendPasswordResetLink::class)->handle('nobody@example.com', '10.1.1.99'))->toThrow(LoginFailed::class);

    for ($i = 0; $i < (int) config('oppam.auth.reset_links_per_ip_per_hour'); $i++) {
        app(SendPasswordResetLink::class)->handle('n'.$i.'@example.com', '10.9.9.9');
    }
    expect(fn () => app(SendPasswordResetLink::class)->handle('another@example.com', '10.9.9.9'))->toThrow(LoginFailed::class);
});
