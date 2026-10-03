<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ModerationHold;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\User;
use App\Notifications\Account\AccountReactivated;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lift a suspension (A03): the account may sign in again, the profile returns to the status it
 * had before (never ACTIVE for a profile that was never approved), paused plans resume with the
 * paused time added back, and held moderation items return to the queues. Typed reason, audited,
 * member emailed (verified address only).
 */
final class ReactivateMember
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

        $reactivated = DB::transaction(function () use ($admin, $member, $reason): User {
            $member = $this->lockMember($member);

            if ($member->trashed()) {
                throw MemberStateConflict::deleted();
            }

            if ($member->status !== UserStatus::Suspended) {
                throw MemberStateConflict::notSuspended();
            }

            $profile = $this->lockProfile($member);
            $before = ['status' => $member->status->value, 'profile_status' => $profile?->status->value];

            $member->forceFill(['status' => UserStatus::Active, 'suspended_at' => null])->save();

            $resumed = 0;
            $released = 0;
            if ($profile !== null) {
                $profile->status = $this->statusToRestore($profile);
                $profile->previous_status = null;
                $profile->save();
                $resumed = $this->resumeSubscriptions($profile);
                $released = $this->releaseModerationItems($profile, ModerationHold::MemberSuspended);
            }

            $this->audit->record('members.reactivated', $member, $before,
                ['status' => UserStatus::Active->value, 'profile_status' => $profile?->status->value, 'plans_resumed' => $resumed, 'moderation_items_released' => $released],
                reason: $reason, actor: $admin, subjectLabel: $profile?->code);

            return $member;
        });

        if ($reactivated->canReceiveAccountMail()) {
            $reactivated->notify(new AccountReactivated);
        }
    }
}
