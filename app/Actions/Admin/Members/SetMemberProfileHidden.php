<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Hide / unhide a member's profile (A03 quick action): a hidden profile leaves search and profile
 * pages but the member can still sign in. Only a live (ACTIVE) profile can be hidden; unhiding
 * returns the status it had. Typed reason, audited.
 */
final class SetMemberProfileHidden
{
    use ChangesMemberState;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, bool $hidden, string $reason): void
    {
        Gate::forUser($admin)->authorize('members.edit');
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $member, $hidden, $reason): void {
            $member = $this->lockMember($member);
            if ($member->trashed()) {
                throw MemberStateConflict::deleted();
            }

            $profile = $this->lockProfile($member);
            $before = $profile?->status;

            if ($hidden) {
                if ($profile === null || $profile->status !== ProfileStatus::Active) {
                    throw MemberStateConflict::notVisible();
                }
                $profile->previous_status = ProfileStatus::Active;
                $profile->status = ProfileStatus::Hidden;
            } else {
                if ($profile === null || $profile->status !== ProfileStatus::Hidden) {
                    throw MemberStateConflict::notHidden();
                }
                $profile->status = $this->statusToRestore($profile);
                $profile->previous_status = null;
            }

            $profile->save();

            $this->audit->record($hidden ? 'members.profile_hidden' : 'members.profile_unhidden', $profile,
                ['status' => $before?->value], ['status' => $profile->status->value],
                reason: $reason, actor: $admin, subjectLabel: $profile->code);
        });
    }
}
