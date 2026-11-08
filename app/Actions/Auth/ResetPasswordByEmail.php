<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountSecurityChange;
use App\Enums\LoginMethod;
use App\Enums\UserRole;
use App\Events\Account\ForceLogout;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Services\Audit\AuditLogger;
use App\Support\Realtime\LiveSignal;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Forgot password — opening the email link (PRD §8.2 method 3; P8.2b). Like ResetPassword (the SMS
 * way): the link is used up, the password set, every session and "stay logged in" cookie of the
 * account ended (session_epoch + remember token), then the member is signed in on this device. The
 * account must still be an active member with that VERIFIED address. Same per-IP limit as sign-in.
 */
final class ResetPasswordByEmail
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly SignInMember $signIn,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AccountChangeRefused the link is unknown, used or expired
     * @throws LoginFailed
     */
    public function handle(string $token, #[SensitiveParameter] string $newPassword, Request $request): User
    {
        $ipKey = 'login:ip:'.$request->ip();
        if ($this->limiter->hit($ipKey, 60) > (int) config('oppam.auth.ip_attempts_per_minute')) {
            throw LoginFailed::lockedOut($this->limiter->availableIn($ipKey));
        }

        $member = DB::transaction(function () use ($token, $newPassword): User {
            $row = DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->lockForUpdate()->first();
            if ($row === null || $row->created_at === null
                || now()->subMinutes((int) config('oppam.auth.reset_link_minutes'))->greaterThan($row->created_at)) {
                throw AccountChangeRefused::resetLinkInvalid();
            }
            DB::table('password_reset_tokens')->where('email', $row->email)->delete();   // single use

            $member = User::query()->where('email', $row->email)->whereNotNull('email_verified_at')
                ->where('role', UserRole::Member->value)->lockForUpdate()->first();
            if ($member === null) {
                throw AccountChangeRefused::resetLinkInvalid();
            }
            if (! $member->isActive()) {
                throw LoginFailed::accountBlocked();
            }

            $member->forceFill([
                'password' => $newPassword,
                'session_epoch' => $member->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();
            LiveSignal::afterCommit(new ForceLogout((string) $member->id));   // other devices' open tabs leave now

            return $member;
        });

        $this->signIn->handle($member, LoginMethod::PasswordReset, remember: false, request: $request);
        $this->audit->record('account.password_reset_by_email', $member, actor: $member, subjectLabel: $member->profile?->code);
        $member->notify(new AccountSecurityChanged(AccountSecurityChange::PasswordResetByEmail));

        return $member;
    }
}
