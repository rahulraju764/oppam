<?php

declare(strict_types=1);

use App\Actions\Auth\LoginWithOtp;
use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\RequestMemberOtp;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\LoginEvent;
use App\Models\User;
use App\ValueObjects\PhoneNumber;

/*
| P1.1 — PRD §8.2 member login: OTP (primary) and mobile / email / profile code + password.
| R-M01-4 no enumeration, R-M01-5 blocked accounts, throttling, remember-me.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
});

function passwordLogin(string $id, string $password = TEST_MEMBER_PASSWORD, bool $remember = false, string $ip = '10.0.0.1'): User
{
    return app(LoginWithPassword::class)->handle($id, $password, $remember, requestWithSession($ip));
}

function failureMessage(Closure $attempt): ?string
{
    return rescue($attempt, fn (Throwable $e) => $e->getMessage(), false);
}

it('signs in with mobile, email or profile code and the password', function (string $kind): void {
    $user = memberWithPhone('+919876543210');
    $user->forceFill(['email' => 'anjali@example.com'])->save();

    $identifier = match ($kind) {
        'mobile' => '98765 43210',
        'mobile with +91' => '+91 9876543210',
        'email, any case' => 'Anjali@Example.com',
        default => strtolower($user->profile->code),
    };

    expect(passwordLogin($identifier)->is($user))->toBeTrue()
        ->and(auth('web')->id())->toBe($user->id)
        ->and(LoginEvent::query()->latest('id')->first()?->method)->toBe(LoginMethod::Password);
})->with(['mobile', 'mobile with +91', 'email, any case', 'profile code, lower case']);

it('R-M01-4: gives the same error for an unknown id, a wrong password and an unverified mobile', function (): void {
    memberWithPhone('+919876543210');
    User::factory()->unverified()->create(['phone' => '+919000000001', 'password' => TEST_MEMBER_PASSWORD]);

    $expected = LoginFailed::invalidCredentials()->getMessage();

    expect(failureMessage(fn () => passwordLogin('9876543210', 'wrong-pass1')))->toBe($expected)
        ->and(failureMessage(fn () => passwordLogin('9000000009', 'wrong-pass1', ip: '10.0.0.2')))->toBe($expected)
        ->and(failureMessage(fn () => passwordLogin('nobody@example.com', ip: '10.0.0.3')))->toBe($expected)
        ->and(failureMessage(fn () => passwordLogin('OPM99999', ip: '10.0.0.4')))->toBe($expected)
        ->and(failureMessage(fn () => passwordLogin('9000000001', ip: '10.0.0.5')))->toBe($expected)
        ->and(auth('web')->check())->toBeFalse();
});

it('R-M01-5: tells a suspended or banned member only after the right password', function (UserStatus $status): void {
    $user = memberWithPhone('+919876543210');
    $user->forceFill(['status' => $status])->save();

    expect(failureMessage(fn () => passwordLogin('9876543210', 'wrong-pass1')))->toBe(LoginFailed::invalidCredentials()->getMessage())
        ->and(failureMessage(fn () => passwordLogin('9876543210')))->toBe(LoginFailed::accountBlocked()->getMessage())
        ->and(auth('web')->check())->toBeFalse();
})->with([UserStatus::Suspended, UserStatus::Banned]);

it('locks a login id after 5 wrong passwords, whatever the IP, known or not', function (string $id): void {
    memberWithPhone('+919876543210');

    foreach (range(1, 5) as $i) {
        rescue(fn () => passwordLogin($id, 'wrong-pass1', ip: "10.0.1.{$i}"), report: false);
    }

    expect(failureMessage(fn () => passwordLogin($id, ip: '10.0.1.99')))->toContain('Too many attempts');
})->with(['known' => '9876543210', 'unknown' => '9000000009']);

it('clears the per-id counter after a successful sign-in', function (): void {
    memberWithPhone('+919876543210');

    foreach (range(1, 4) as $_) {
        rescue(fn () => passwordLogin('9876543210', 'wrong-pass1'), report: false);
    }
    passwordLogin('9876543210');
    auth('web')->logout();

    rescue(fn () => passwordLogin('9876543210', 'wrong-pass1'), report: false);
    expect(passwordLogin('9876543210'))->toBeInstanceOf(User::class);
});

it('caps password attempts per IP per minute', function (): void {
    config(['oppam.auth.ip_attempts_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        rescue(fn () => passwordLogin("900000000{$i}", 'x-pass-1'), report: false);
    }

    expect(failureMessage(fn () => passwordLogin('9000000009', 'x-pass-1')))->toContain('Too many attempts');
});

it('remembers the member for 30 days only when asked', function (): void {
    $user = memberWithPhone('+919876543210');

    passwordLogin('9876543210', remember: true);

    $cookie = collect(app('cookie')->getQueuedCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));

    expect($user->refresh()->getRememberToken())->not->toBeNull()
        ->and($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());
});

it('R-M01-4: answers "code sent" for every number but texts only registered, verified ones', function (): void {
    memberWithPhone('+919876543210');
    User::factory()->unverified()->create(['phone' => '+919000000001']);

    $request = app(RequestMemberOtp::class);
    $request->handle(PhoneNumber::fromParts('91', '9876543210'), OtpPurpose::Login, '10.0.0.1');
    $request->handle(PhoneNumber::fromParts('91', '9000000009'), OtpPurpose::Login, '10.0.0.1');   // no account
    $request->handle(PhoneNumber::fromParts('91', '9000000001'), OtpPurpose::Login, '10.0.0.1');   // unverified

    expect(collect($this->sms->sent)->pluck('to')->all())->toBe(['+919876543210']);
});

it('refuses to issue codes for other purposes through the member request', function (): void {
    expect(fn () => app(RequestMemberOtp::class)->handle(PhoneNumber::fromParts('91', '9876543210'), OtpPurpose::Register, '10.0.0.1'))
        ->toThrow(InvalidArgumentException::class);
});

it('signs in with the LOGIN code', function (): void {
    $user = memberWithPhone('+919876543210');
    $phone = PhoneNumber::fromParts('91', '9876543210');
    app(RequestMemberOtp::class)->handle($phone, OtpPurpose::Login, '10.0.0.1');

    app(LoginWithOtp::class)->handle($phone, (string) $this->sms->lastCodeFor('+919876543210'), false, requestWithSession());

    expect(auth('web')->id())->toBe($user->id)
        ->and(LoginEvent::query()->sole()->method)->toBe(LoginMethod::Otp);
});

it('R-M01-4: a code for a number with no account fails like a wrong code', function (): void {
    expect(failureMessage(fn () => app(LoginWithOtp::class)->handle(PhoneNumber::fromParts('91', '9000000009'), '123456', false, requestWithSession())))
        ->toBe(OtpInvalid::wrongOrExpired()->getMessage());
});

it('R-M01-5: tells a suspended member only after the right code', function (): void {
    $user = memberWithPhone('+919876543210');
    $user->forceFill(['status' => UserStatus::Suspended])->save();
    $phone = PhoneNumber::fromParts('91', '9876543210');
    app(RequestMemberOtp::class)->handle($phone, OtpPurpose::Login, '10.0.0.1');

    expect(failureMessage(fn () => app(LoginWithOtp::class)->handle($phone, (string) $this->sms->lastCodeFor('+919876543210'), false, requestWithSession())))
        ->toBe(LoginFailed::accountBlocked()->getMessage())
        ->and(auth('web')->check())->toBeFalse();
});

it('regenerates the session id on sign-in (fixation)', function (): void {
    memberWithPhone('+919876543210');
    $request = requestWithSession();
    $before = $request->session()->getId();

    app(LoginWithPassword::class)->handle('9876543210', TEST_MEMBER_PASSWORD, false, $request);

    expect($request->session()->getId())->not->toBe($before);
});
