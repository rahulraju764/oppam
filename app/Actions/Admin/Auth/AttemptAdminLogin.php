<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Step 1 of admin sign-in: email + password (PRD A01). On success the admin is NOT signed in —
 * the caller starts the pending-2FA state. Rules:
 * - 3 failed attempts for an email → that email is locked for 30 minutes. The counter is keyed by
 *   the email whether or not it belongs to an admin, and the error is the same either way, so the
 *   form never reveals which emails are admins (no enumeration; constant-time password check).
 * - A per-IP limiter caps spraying across many emails.
 * - Suspended or 2FA-locked accounts fail with the same generic message.
 */
final class AttemptAdminLogin
{
    private const IP_ATTEMPTS_PER_MINUTE = 20;

    /** A real hash of a random string, made once per process: unknown emails still pay for one Hash::check. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(string $email, string $password, string $ip): AdminUser
    {
        $emailKey = 'admin-login:email:'.sha1(Str::lower(trim($email)));
        $ipKey = 'admin-login:ip:'.$ip;

        // Count FIRST, then check: the cache increment is atomic, so concurrent requests can't
        // all slip past a "too many?" check made before any of them counted (P0.5 review).
        // The attempt that is itself over the limit is refused before the password is checked.
        $overLimit = [
            $emailKey => $this->limiter->hit($emailKey, config('oppam.admin.lockout_minutes') * 60) > config('oppam.admin.max_login_attempts'),
            $ipKey => $this->limiter->hit($ipKey, 60) > self::IP_ATTEMPTS_PER_MINUTE,
        ];

        foreach ($overLimit as $key => $blocked) {
            if ($blocked) {
                throw AdminAuthenticationFailed::lockedOut($this->limiter->availableIn($key));
            }
        }

        $admin = AdminUser::query()->where('email', Str::lower(trim($email)))->first();
        $passwordMatches = Hash::check($password, $admin !== null ? $admin->password : (self::$dummyHash ??= Hash::make(Str::random(40))));

        if ($admin === null || ! $passwordMatches || ! $admin->isActive() || $admin->isLocked()) {
            if ($admin !== null) {
                $this->audit->record('admin.login_failed', $admin, actor: $admin, subjectLabel: $admin->email);
            }

            throw AdminAuthenticationFailed::invalidCredentials();
        }

        // A successful sign-in doesn't count against the email (the IP window still does).
        $this->limiter->clear($emailKey);

        return $admin;
    }
}
