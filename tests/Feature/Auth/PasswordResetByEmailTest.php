<?php

declare(strict_types=1);

use App\Actions\Auth\ResetPasswordByEmail;
use App\Actions\Auth\SendPasswordResetLink;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Notifications\Auth\PasswordResetLink;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| P8.2b — forgot password by email link (PRD §8.2 method 3; owner decisions 2026-11-08): a link is
| mailed only to a verified member address, with the same answer for any address (R-M01-4); it is
| single-use, lasts 60 minutes, and — like the SMS reset — ends every session (epoch + remember
| token), signs in on this device and sends a security notice. Limited per address and per IP.
*/

beforeEach(function (): void {
    fakeSms();
    Notification::fake();
});

function rpMember(bool $verifiedEmail = true): User
{
    $member = memberWithPhone('+919876533001');
    $member->forceFill(['email' => 'pw@example.com', 'email_verified_at' => $verifiedEmail ? now() : null, 'remember_token' => 'old-remember-token'])->save();

    return $member->refresh();
}

function rpMailedToken(): ?string
{
    $token = null;
    Notification::assertSentOnDemand(PasswordResetLink::class, function (PasswordResetLink $n) use (&$token): bool {
        $token = basename($n->url);

        return true;
    });

    return $token;
}

it('§8.2 / R-M01-4: a link goes only to a verified member address; any other address gets the same answer and no mail', function (string $case): void {
    $member = rpMember($case !== 'unverified');
    if ($case === 'suspended') {
        $member->forceFill(['status' => UserStatus::Suspended])->save();
    }
    if ($case === 'bureau login') {
        $member->forceFill(['role' => App\Enums\UserRole::Broker])->save();
    }
    $address = $case === 'unknown' ? 'nobody@example.com' : 'pw@example.com';

    app(SendPasswordResetLink::class)->handle($address, '10.1.1.1');   // never throws for an unknown address

    if ($case === 'verified') {
        Notification::assertSentOnDemand(PasswordResetLink::class, fn ($n, array $c, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'pw@example.com');
        expect(DB::table('password_reset_tokens')->where('email', 'pw@example.com')->value('token'))->not->toBe(rpMailedToken());   // stored hashed
    } else {
        Notification::assertSentOnDemandTimes(PasswordResetLink::class, 0);
    }
})->with(['verified', 'unverified', 'unknown', 'suspended', 'bureau login']);

it('the link resets the password once, ends every session, signs in here and notifies', function (): void {
    $member = rpMember();
    $epoch = $member->session_epoch;
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $token = (string) rpMailedToken();

    app(ResetPasswordByEmail::class)->handle($token, 'FreshPass2026', requestWithSession());

    $remember = $member->remember_token;
    $member->refresh();
    expect($member->remember_token)->not->toBe($remember);   // other devices' "stay logged in" cookies die
    expect(Hash::check('FreshPass2026', $member->password))->toBeTrue()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and(auth('web')->id())->toBe($member->id);
    Notification::assertSentTo($member, AccountSecurityChanged::class);

    expect(fn () => app(ResetPasswordByEmail::class)->handle($token, 'OtherPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);   // single use
});

it('an expired, unknown or replaced link is refused and changes nothing', function (): void {
    $member = rpMember();
    expect(fn () => app(ResetPasswordByEmail::class)->handle(str_repeat('x', 64), 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);

    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $first = (string) rpMailedToken();
    Notification::fake();
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    expect(fn () => app(ResetPasswordByEmail::class)->handle($first, 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);   // replaced by the newer link

    $second = (string) rpMailedToken();
    $this->travel((int) config('oppam.auth.reset_link_minutes') + 1)->minutes();
    expect(fn () => app(ResetPasswordByEmail::class)->handle($second, 'FreshPass2026', requestWithSession()))
        ->toThrow(AccountChangeRefused::class);
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->refresh()->password))->toBeTrue();
});

it('a link for an account suspended since is refused with the blocked message', function (): void {
    $member = rpMember();
    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');
    $member->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => app(ResetPasswordByEmail::class)->handle((string) rpMailedToken(), 'FreshPass2026', requestWithSession()))
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

it('P8.2b review: the reset link is never queued — the token can\'t sit in jobs / failed_jobs / Redis', function (): void {
    Notification::fake();
    Illuminate\Support\Facades\Queue::fake();
    rpMember();

    app(SendPasswordResetLink::class)->handle('pw@example.com', '10.1.1.1');

    expect(new PasswordResetLink('https://x', 60))->not->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    Illuminate\Support\Facades\Queue::assertNotPushed(Illuminate\Notifications\SendQueuedNotifications::class,
        fn ($job): bool => $job->notification instanceof PasswordResetLink);
    Notification::assertSentOnDemandTimes(PasswordResetLink::class, 1);   // sent now (after the response), not queued
});

it('rule 10: using reset links is limited per IP like sign-in', function (): void {
    rpMember();
    for ($i = 0; $i < (int) config('oppam.auth.ip_attempts_per_minute'); $i++) {
        try {
            app(ResetPasswordByEmail::class)->handle(str_repeat('x', 64), 'FreshPass2026', requestWithSession('10.5.5.5'));
        } catch (AccountChangeRefused) {
        }
    }

    expect(fn () => app(ResetPasswordByEmail::class)->handle(str_repeat('x', 64), 'FreshPass2026', requestWithSession('10.5.5.5')))
        ->toThrow(LoginFailed::class);
});
