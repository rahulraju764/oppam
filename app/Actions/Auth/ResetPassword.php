<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Forgot password (PRD §8.2 method 3): PASSWORD_RESET code + new password. Every other session
 * and "stay logged in" cookie of the account ends (session_epoch + remember token), because a
 * reset is what people do when they think someone else is in their account. Then the member is
 * signed in on this device. Blocked accounts learn their status only after the right code.
 */
final class ResetPassword
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly VerifyOtp $verifyOtp,
        private readonly SignInMember $signIn,
    ) {}

    /**
     * @throws OtpInvalid
     * @throws LoginFailed
     */
    public function handle(PhoneNumber $phone, string $code, #[SensitiveParameter] string $newPassword, Request $request): User
    {
        $ipKey = 'login:ip:'.$request->ip();

        if ($this->limiter->hit($ipKey, 60) > (int) config('oppam.auth.ip_attempts_per_minute')) {
            throw LoginFailed::lockedOut($this->limiter->availableIn($ipKey));
        }

        $this->verifyOtp->handle($phone, OtpPurpose::PasswordReset, $code);

        $user = User::query()->where('phone', $phone->e164())->whereNotNull('phone_verified_at')->first()
            ?? throw OtpInvalid::wrongOrExpired();

        if (! $user->isActive()) {
            throw LoginFailed::accountBlocked();
        }

        DB::transaction(function () use ($user, $newPassword): void {
            $user->forceFill([
                'password' => $newPassword,
                'session_epoch' => $user->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();
        });

        $this->signIn->handle($user, LoginMethod::PasswordReset, remember: false, request: $request);

        return $user;
    }
}
