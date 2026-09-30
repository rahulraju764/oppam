<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Models\AdminUser;
use App\Notifications\Admin\AdminAccountLocked;
use App\Services\Admin\TwoFactorAuthenticator;
use App\Services\Audit\AuditLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * The 2FA challenge at every admin sign-in (PRD A01): a 6-digit TOTP code, or one of the
 * single-use recovery codes. 5 wrong codes → the account is locked for 30 minutes and every
 * other super admin is alerted by email; a super admin can unlock earlier (Staff screen).
 * The failure counter is incremented under a row lock, and codes are rate-limited per admin, so
 * parallel requests can't try more than the allowed number of codes (P0.5 review).
 */
final class VerifyAdminSecondFactor
{
    /** Codes tried per admin per minute, on top of the 5-strike lock. */
    private const CODES_PER_MINUTE = 5;

    public function __construct(
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(AdminUser $admin, string $code, bool $isRecoveryCode = false): void
    {
        // Fresh from the database: a lock applied by a parallel request must be honoured here.
        $admin = $admin->fresh() ?? $admin;

        if ($admin->isLocked() || ! $admin->hasConfirmedTwoFactor()) {
            throw AdminAuthenticationFailed::accountLocked();
        }

        $limiterKey = 'admin-2fa:'.$admin->id;

        if ($this->limiter->hit($limiterKey, 60) > self::CODES_PER_MINUTE) {
            throw AdminAuthenticationFailed::lockedOut($this->limiter->availableIn($limiterKey));
        }

        $valid = $isRecoveryCode
            ? $this->twoFactor->consumeRecoveryCode($admin, $code)
            : $this->twoFactor->verify((string) $admin->two_factor_secret, $code);

        if ($valid) {
            if ($isRecoveryCode) {
                $this->audit->record('admin.recovery_code_used', $admin, actor: $admin, subjectLabel: $admin->email);
            }

            return;
        }

        $this->recordFailure($admin);
    }

    private function recordFailure(AdminUser $admin): never
    {
        // Read-modify-write under a row lock: concurrent wrong codes are all counted.
        $attempts = DB::transaction(function () use ($admin): int {
            /** @var AdminUser $locked */
            $locked = AdminUser::query()->lockForUpdate()->findOrFail($admin->id);
            $attempts = $locked->failed_two_factor_attempts + 1;
            $lockNow = $attempts >= config('oppam.admin.max_two_factor_attempts');

            // The counter restarts with the lock, so an expired lock gives five fresh attempts.
            $locked->forceFill([
                'failed_two_factor_attempts' => $lockNow ? 0 : $attempts,
                'locked_until' => $lockNow ? now()->addMinutes(config('oppam.admin.lockout_minutes')) : $locked->locked_until,
            ])->save();

            return $attempts;
        });

        if ($attempts < config('oppam.admin.max_two_factor_attempts')) {
            $this->audit->record('admin.two_factor_failed', $admin, actor: $admin, subjectLabel: $admin->email);

            throw AdminAuthenticationFailed::invalidCode();
        }

        $this->audit->record('admin.locked', $admin, after: ['failed_two_factor_attempts' => $attempts], actor: $admin, subjectLabel: $admin->email);

        $superAdmins = Role::findByName('super_admin', 'admin')->users()->where('admin_users.id', '!=', $admin->id)->get();
        Notification::send($superAdmins, new AdminAccountLocked($admin->email));

        throw AdminAuthenticationFailed::accountLocked();
    }
}
