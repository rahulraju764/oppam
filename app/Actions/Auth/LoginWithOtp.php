<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\ClosedAccountLookup;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\AccountClosed;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * Mobile + OTP sign-in (PRD §8.2 method 1, the primary one). The code check is the proof of
 * identity; only after it does a blocked account learn its status (R-M01-5). A per-IP limit
 * stops one client guessing codes across many numbers.
 */
final class LoginWithOtp
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly VerifyOtp $verifyOtp,
        private readonly SignInMember $signIn,
        private readonly ClosedAccountLookup $closedAccounts,
    ) {}

    /**
     * @throws OtpInvalid
     * @throws LoginFailed
     * @throws AccountClosed the right code for an account the member closed (restore step)
     */
    public function handle(PhoneNumber $phone, string $code, bool $remember, Request $request): User
    {
        $ipKey = 'login:ip:'.$request->ip();

        if ($this->limiter->hit($ipKey, 60) > (int) config('oppam.auth.ip_attempts_per_minute')) {
            throw LoginFailed::lockedOut($this->limiter->availableIn($ipKey));
        }

        $this->verifyOtp->handle($phone, OtpPurpose::Login, $code);

        $user = User::query()->where('phone', $phone->e164())->whereNotNull('phone_verified_at')->first();

        if ($user === null) {
            // M14 (P8.2c): the code proved the number — an account its owner closed may be restored.
            $closed = $this->closedAccounts->forPhone($phone);

            throw $closed !== null ? new AccountClosed($closed) : OtpInvalid::wrongOrExpired();
        }

        if (! $user->isActive()) {
            throw LoginFailed::accountBlocked();
        }

        $this->signIn->handle($user, LoginMethod::Otp, $remember, $request);

        return $user;
    }
}
