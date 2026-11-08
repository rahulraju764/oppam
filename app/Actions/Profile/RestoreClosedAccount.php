<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Domain\Auth\ClosedAccountLookup;
use App\Enums\ModerationHold;
use App\Enums\UserStatus;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A member brings back the account they closed (M14, P8.2c — owner 2026-11-08: by signing in).
 * Called only from the /account/restore step, after the member proved who they are with their
 * password or a login code. Re-checks under the lock that the account is still restorable
 * (ClosedAccountLookup); then the account comes back ACTIVE, the profile gets its earlier status,
 * paused plans resume and held moderation items return to the queues — the same steps as an A03
 * restore. The caller signs the member in afterwards.
 */
final class RestoreClosedAccount
{
    use ChangesMemberState;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ClosedAccountLookup $closed,
    ) {}

    /** @throws LoginFailed when the account can no longer be restored (same text as an unknown one) */
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $member = $this->lockMember($user);
            $closure = $this->closed->closureFor($member);

            if ($closure === null || $member->suspended_at !== null) {
                throw LoginFailed::invalidCredentials();
            }

            $profile = $this->lockProfile($member);

            $member->restore();
            $member->forceFill(['status' => UserStatus::Active])->save();

            if ($profile !== null) {
                $profile->restore();
                $profile->status = $this->statusToRestore($profile);
                $profile->previous_status = null;
                $profile->save();

                $this->resumeSubscriptions($profile);
                $this->releaseModerationItems($profile, ModerationHold::MemberDeleted);
            }

            $closure->forceFill(['restored_at' => now()])->save();

            $this->audit->record('account.restored_by_member', $member, ['status' => UserStatus::Deleted->value],
                ['status' => UserStatus::Active->value, 'profile_status' => $profile?->status->value],
                actor: $member, subjectLabel: $profile?->code);

            return $member;
        });
    }
}
