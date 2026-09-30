<?php

declare(strict_types=1);

use App\Actions\Auth\RequestMemberOtp;
use App\Actions\Auth\ResetPassword;
use App\Actions\Auth\SignInMember;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\LoginEvent;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Hash;

/*
| P1.1 — PRD §8.2 method 3: forgot password by SMS code. R-M01-4 (same answer for unknown
| numbers), R-M01-5, other sessions end on reset.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
    $this->user = memberWithPhone('+919876543210');
    $this->phone = PhoneNumber::fromParts('91', '9876543210');
});

function resetCode(object $test): string
{
    app(RequestMemberOtp::class)->handle($test->phone, OtpPurpose::PasswordReset, '10.0.0.1');

    return (string) $test->sms->lastCodeFor('+919876543210', OtpPurpose::PasswordReset);
}

it('sets the new password, ends every other session and signs in here', function (): void {
    $epoch = $this->user->session_epoch;
    $token = $this->user->getRememberToken();

    app(ResetPassword::class)->handle($this->phone, resetCode($this), 'newpass2026', requestWithSession());

    $user = $this->user->refresh();

    expect(Hash::check('newpass2026', $user->password))->toBeTrue()
        ->and($user->session_epoch)->toBe($epoch + 1)
        ->and($user->getRememberToken())->not->toBe($token)
        ->and(auth('web')->id())->toBe($user->id)
        ->and(session(SignInMember::EPOCH_SESSION_KEY))->toBe($epoch + 1)
        ->and(LoginEvent::query()->sole()->method)->toBe(LoginMethod::PasswordReset);
});

it('refuses a LOGIN code for a password reset', function (): void {
    app(RequestMemberOtp::class)->handle($this->phone, OtpPurpose::Login, '10.0.0.1');
    $loginCode = (string) $this->sms->lastCodeFor('+919876543210', OtpPurpose::Login);

    expect(fn () => app(ResetPassword::class)->handle($this->phone, $loginCode, 'newpass2026', requestWithSession()))
        ->toThrow(OtpInvalid::class);

    expect(Hash::check(TEST_MEMBER_PASSWORD, $this->user->refresh()->password))->toBeTrue();
});

it('R-M01-4: an unknown number gets no SMS and fails like a wrong code', function (): void {
    $unknown = PhoneNumber::fromParts('91', '9000000009');
    app(RequestMemberOtp::class)->handle($unknown, OtpPurpose::PasswordReset, '10.0.0.1');

    expect($this->sms->sent)->toBe([])
        ->and(fn () => app(ResetPassword::class)->handle($unknown, '123456', 'newpass2026', requestWithSession()))
        ->toThrow(OtpInvalid::class, OtpInvalid::wrongOrExpired()->getMessage());
});

it('R-M01-5: a suspended member proves the number but cannot reset or sign in', function (): void {
    $this->user->forceFill(['status' => UserStatus::Suspended])->save();
    $code = resetCode($this);

    expect(fn () => app(ResetPassword::class)->handle($this->phone, $code, 'newpass2026', requestWithSession()))
        ->toThrow(LoginFailed::class, LoginFailed::accountBlocked()->getMessage());

    expect(Hash::check(TEST_MEMBER_PASSWORD, $this->user->refresh()->password))->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();
});
