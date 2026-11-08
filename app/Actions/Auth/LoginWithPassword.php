<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\ClosedAccountLookup;
use App\Enums\LoginMethod;
use App\Exceptions\Auth\AccountClosed;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Queries\Auth\FindUserByLoginId;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Mobile / email / profile code + password (PRD §8.2 method 2).
 * - R-M01-4: unknown id, wrong password and unverified mobile all give the same error, and an
 *   unknown id still pays for one Hash::check (no timing difference).
 * - Throttled per login id (config: 5 per 15 min) and per IP; counted before checking.
 * - R-M01-5: a suspended/banned account is told so only AFTER the right password.
 * An account whose mobile was never verified can't sign in here: it finishes registration.
 */
final class LoginWithPassword
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly FindUserByLoginId $identifiers,
        private readonly SignInMember $signIn,
        private readonly ClosedAccountLookup $closedAccounts,
    ) {}

    /**
     * @throws LoginFailed
     * @throws AccountClosed the right password for an account the member closed (restore step)
     */
    public function handle(string $identifier, #[SensitiveParameter] string $password, bool $remember, Request $request): User
    {
        $idKey = 'login:id:'.$this->identifiers->throttleKey($identifier);
        $ipKey = 'login:ip:'.$request->ip();

        $limits = [
            $idKey => [(int) config('oppam.auth.password_lockout_minutes') * 60, (int) config('oppam.auth.password_attempts')],
            $ipKey => [60, (int) config('oppam.auth.ip_attempts_per_minute')],
        ];

        foreach ($limits as $key => [$decay, $max]) {
            if ($this->limiter->hit($key, $decay) > $max) {
                throw LoginFailed::lockedOut($this->limiter->availableIn($key));
            }
        }

        $user = $this->identifiers->handle($identifier);
        // M14 (P8.2c): an account the member closed themselves is checked like any other — one
        // Hash::check either way — and only the right password reveals that it can be restored.
        $closed = $user === null ? $this->closedAccounts->forLoginId($identifier) : null;
        $candidate = $user ?? $closed;
        $matches = Hash::check($password, $candidate !== null ? $candidate->password : (self::$dummyHash ??= Hash::make(Str::random(40))));

        if ($closed !== null && $matches) {
            $this->limiter->clear($idKey);

            throw new AccountClosed($closed);
        }

        if ($user === null || ! $matches || ! $user->hasVerifiedPhone()) {
            throw LoginFailed::invalidCredentials();
        }

        if (! $user->isActive()) {
            throw LoginFailed::accountBlocked();
        }

        $this->limiter->clear($idKey);
        $this->signIn->handle($user, LoginMethod::Password, $remember, $request);

        return $user;
    }
}
