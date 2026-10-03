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
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a member — stage one of the two-stage deletion (A03). The admin types the member's code
 * to confirm. The account and profile are soft-deleted (status DELETED, sessions ended, plans
 * paused, waiting moderation items held) and can be restored for `members.purge_after_days`
 * (default 30); after that PurgeDeletedMembers anonymises them. One member at a time — there is
 * no bulk delete (A03). Typed reason, audited.
 */
final class DeleteMember
{
    use ChangesMemberState;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, string $typedCode, string $reason): void
    {
        Gate::forUser($admin)->authorize('members.delete');
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $member, $typedCode, $reason): void {
            $member = $this->lockMember($member);

            if ($member->trashed() || $member->status === UserStatus::Deleted) {
                throw MemberStateConflict::alreadyDeleted();
            }

            $profile = $this->lockProfile($member);
            $expected = $profile !== null ? $profile->code : $member->phone;

            if (! hash_equals(mb_strtoupper($expected), mb_strtoupper(trim($typedCode)))) {
                throw ValidationException::withMessages(['confirmCode' => __('Type the member code exactly to confirm.')]);
            }

            $before = ['status' => $member->status->value, 'profile_status' => $profile?->status->value];

            $this->revokeSessions($member);
            $member->forceFill(['status' => UserStatus::Deleted])->save();
            $member->delete();

            $paused = 0;
            $held = 0;
            if ($profile !== null) {
                $this->rememberStatus($profile);
                $profile->status = ProfileStatus::Deleted;
                $profile->save();
                $profile->delete();
                $paused = $this->pauseSubscriptions($profile);
                // Items already held by a suspension stay held under that category.
                $held = $this->holdModerationItems($profile, ModerationHold::MemberDeleted);
            }

            $this->audit->record('members.deleted', $member, $before,
                ['status' => UserStatus::Deleted->value, 'plans_paused' => $paused, 'moderation_items_held' => $held],
                reason: $reason, actor: $admin, subjectLabel: $profile?->code);
        });
    }
}
