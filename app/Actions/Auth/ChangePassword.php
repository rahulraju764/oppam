<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountSecurityChange;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\SessionRotation;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Change password from the /settings Account tab (PRD M14 / M01). The current password is checked
 * first — at most `oppam.auth.password_change_attempts` wrong tries per member, then a wait — and
 * the new one must differ from it (its strength is validated by the form with
 * MemberPassword::rule()). Every other device is signed out (SessionRotation), this one stays;
 * then a security notice and an audit row (never the password). Never in a support session.
 */
final class ChangePassword
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly SessionRotation $rotation,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AccountChangeRefused
     * @throws ImpersonationRestricted
     */
    public function handle(User $member, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, Request $request): void
    {
        $this->impersonation->assertAllowed();
        if (! $member->isActive() || $member->role !== UserRole::Member) {
            throw new NotFoundHttpException;
        }

        // Counted before the check (parallel requests can't all slip under the limit); cleared on success.
        $key = 'password-change:'.$member->id;
        if ($this->limiter->hit($key, (int) config('oppam.auth.password_change_lockout_minutes') * 60) > (int) config('oppam.auth.password_change_attempts')) {
            throw AccountChangeRefused::passwordLocked($this->limiter->availableIn($key));
        }

        if (! Hash::check($current, $member->password)) {
            throw AccountChangeRefused::wrongPassword();
        }
        $this->limiter->clear($key);

        if (Hash::check($new, $member->password)) {
            throw AccountChangeRefused::samePassword();
        }

        $this->rotation->rotateKeepingThisDevice($member, $request, function (User $locked) use ($new, $member): void {
            $locked->forceFill(['password' => $new]);   // hashed by the model cast
            $this->audit->record('account.password_changed', $member, actor: $member, subjectLabel: $member->profile?->code);
        });

        $member->notify(new AccountSecurityChanged(AccountSecurityChange::PasswordChanged));
    }
}
