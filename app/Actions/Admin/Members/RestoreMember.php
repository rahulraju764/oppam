<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ModerationHold;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Undo a deletion within the restore window (A03, `members.purge_after_days`). A member who was
 * suspended when deleted comes back suspended (their moderation items stay held under the
 * suspension); anyone else comes back active with the profile's earlier status, plans resumed and
 * held moderation items back in the queues. Typed reason, audited.
 */
final class RestoreMember
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
        Gate::forUser($admin)->authorize('members.delete');
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $member, $reason): void {
            $member = $this->lockMember($member);

            if (! $member->trashed()) {
                throw MemberStateConflict::notDeleted();
            }

            if ($member->anonymised_at !== null
                || $member->deleted_at?->lte(now()->subDays(Settings::int(SettingKey::MembersPurgeAfterDays))) === true) {
                throw MemberStateConflict::restoreWindowClosed();
            }

            $profile = $this->lockProfile($member);
            $stillSuspended = $member->suspended_at !== null;

            $member->restore();
            $member->forceFill(['status' => $stillSuspended ? UserStatus::Suspended : UserStatus::Active])->save();

            if ($profile !== null) {
                $profile->restore();

                if ($stillSuspended) {
                    // previous_status still holds the pre-suspension status for ReactivateMember.
                    $profile->status = ProfileStatus::Suspended;
                    $this->releaseModerationItems($profile, ModerationHold::MemberDeleted, stillHeldAs: ModerationHold::MemberSuspended);
                } else {
                    $profile->status = $this->statusToRestore($profile);
                    $profile->previous_status = null;
                    $this->resumeSubscriptions($profile);
                    $this->releaseModerationItems($profile, ModerationHold::MemberDeleted);
                }

                $profile->save();
            }

            $this->audit->record('members.restored', $member, ['status' => UserStatus::Deleted->value],
                ['status' => $member->status->value, 'profile_status' => $profile?->status->value],
                reason: $reason, actor: $admin, subjectLabel: $profile?->code);
        });
    }
}
