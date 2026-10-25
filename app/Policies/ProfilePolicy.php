<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

/**
 * Member abilities on profiles, and the bureau branch for broker-managed profiles (§11A B.6,
 * P7.3): a bureau login acts on a managed profile with its bureau permission inside
 * ManagedProfileScope (own bureau, unclaimed, assigned-only staff on their own clients) — so the
 * M02 wizard Actions serve the bureau form unchanged (B.17 "the same rule set as M02").
 */
final class ProfilePolicy
{
    /** What the bureau may still edit: like a member, plus a deactivated (HIDDEN) client. */
    private const BUREAU_EDITABLE = [ProfileStatus::Draft, ProfileStatus::Rejected, ProfileStatus::Active, ProfileStatus::Hidden];

    public function __construct(private readonly ManagedProfileScope $managed) {}

    /**
     * Fill in / change the profile through the wizard (M02): the owner, while the profile is a
     * DRAFT, was REJECTED ("Edit & resubmit", R-M02-5) or is live (ACTIVE: identity fields stay
     * locked, R-M02-1; changed free text waits for review, R-M02-4). Not while PENDING_REVIEW.
     */
    public function editWizard(User $user, Profile $profile): bool
    {
        if ($this->isBureau($user)) {
            return $this->bureau($user, $profile, BureauPermission::ProfilesEdit)
                && in_array($profile->status, self::BUREAU_EDITABLE, true);
        }

        return $profile->user_id === $user->id
            && $user->isActive()
            && in_array($profile->status, [ProfileStatus::Draft, ProfileStatus::Rejected, ProfileStatus::Active], true);
    }

    /**
     * Send a DRAFT / REJECTED profile to A04 (R-M02-2): the member who may edit it, or a bureau
     * login with `profiles.submit_for_review` on its managed client (R-M13-14 — no auto-approval).
     */
    public function submitForReview(User $user, Profile $profile): bool
    {
        if ($this->isBureau($user)) {
            return $this->bureau($user, $profile, BureauPermission::ProfilesSubmit);
        }

        return $this->editWizard($user, $profile);
    }

    /**
     * Upload, order, caption and delete photos and the horoscope (M11): the owner, while their
     * account is active and the profile isn't suspended or deleted. Live profiles may change
     * photos too — every new photo goes through A04 moderation first.
     */
    public function managePhotos(User $user, Profile $profile): bool
    {
        $open = ! in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true);

        if ($this->isBureau($user)) {
            return $open && $this->bureau($user, $profile, BureauPermission::ProfilesEdit);
        }

        return $profile->user_id === $user->id && $user->isActive() && $open;
    }

    private function isBureau(User $user): bool
    {
        return $user->role === UserRole::Broker;
    }

    private function bureau(User $user, Profile $profile, BureauPermission $permission): bool
    {
        return $user->isActive() && ! $profile->trashed() && $this->managed->allows($user, $profile, $permission);
    }
}
