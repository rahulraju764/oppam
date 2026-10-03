<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ModerationHold;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\User;
use App\Notifications\Account\AccountSuspended;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Suspend a member (A03): the account can't sign in and every session ends on its next request;
 * the profile becomes SUSPENDED (out of search, matches and profile pages — only ACTIVE is
 * visible); running plans pause; waiting moderation items leave the queues. Typed reason,
 * audited; the member gets a neutral email (verified address only). Live ForceLogout and
 * conversation freeze arrive with P3.1 / P4.
 */
final class SuspendMember
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
        Gate::forUser($admin)->authorize('members.suspend');
        $reason = $this->validatedReason($reason);

        $suspended = DB::transaction(function () use ($admin, $member, $reason): User {
            $member = $this->lockMember($member);

            if ($member->status !== UserStatus::Active || $member->trashed()) {
                throw MemberStateConflict::notActive();
            }

            $profile = $this->lockProfile($member);
            $before = ['status' => $member->status->value, 'profile_status' => $profile?->status->value];

            $this->revokeSessions($member);
            $member->forceFill(['status' => UserStatus::Suspended, 'suspended_at' => now()])->save();

            $paused = 0;
            $held = 0;
            if ($profile !== null) {
                $this->rememberStatus($profile);
                $profile->status = ProfileStatus::Suspended;
                $profile->save();
                $paused = $this->pauseSubscriptions($profile);
                $held = $this->holdModerationItems($profile, ModerationHold::MemberSuspended);
            }

            $this->audit->record('members.suspended', $member, $before,
                ['status' => UserStatus::Suspended->value, 'profile_status' => $profile?->status->value, 'plans_paused' => $paused, 'moderation_items_held' => $held],
                reason: $reason, actor: $admin, subjectLabel: $profile?->code);

            return $member;
        });

        if ($suspended->canReceiveAccountMail()) {
            $suspended->notify(new AccountSuspended);
        }
    }
}
