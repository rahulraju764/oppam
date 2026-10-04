<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\User;
use App\Notifications\Account\PasswordResetByAdmin;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin "reset password" (A03 quick action, P1.7b): the current password stops working (replaced
 * by a random one nobody knows), every session and "stay logged in" cookie ends, and the member
 * sets a new password themselves through "Forgot password" (an OTP to their own mobile) — the
 * admin never sees or chooses a password. Needs `members.edit` and a typed reason; audited;
 * the member is emailed (verified address only).
 */
final class ResetMemberPassword
{
    use ChangesMemberState;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, string $reason): void
    {
        Gate::forUser($admin)->authorize('members.edit');
        $reason = $this->validatedReason($reason);

        $member = DB::transaction(function () use ($admin, $member, $reason): User {
            $member = $this->lockMember($member);
            if ($member->trashed()) {
                throw MemberStateConflict::deleted();
            }

            // "Forgot password" needs a verified mobile: without one the member could never sign in
            // again (P1.7b review). An unverified registration gets "Resend verification code".
            if (! $member->hasVerifiedPhone()) {
                throw MemberStateConflict::phoneNotVerified();
            }

            $this->revokeSessions($member);
            $member->forceFill(['password' => Str::random(64)])->save();   // hashed cast; never shown to anyone

            $this->audit->record('members.password_reset', $member, reason: $reason, actor: $admin,
                subjectLabel: $member->profile()->withTrashed()->value('code'));

            return $member;
        });

        if ($member->canReceiveAccountMail()) {
            $member->notify(new PasswordResetByAdmin);
        }
    }
}
