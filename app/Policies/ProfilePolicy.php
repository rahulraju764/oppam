<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;

/**
 * Member abilities on profiles. Broker-managed profiles get their own bureau rules (§11A).
 */
final class ProfilePolicy
{
    /**
     * Fill in / change the profile through the wizard (M02): the owner, while the profile is a
     * DRAFT, was REJECTED ("Edit & resubmit", R-M02-5) or is live (ACTIVE: identity fields stay
     * locked, R-M02-1; changed free text waits for review, R-M02-4). Not while PENDING_REVIEW.
     */
    public function editWizard(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->id
            && $user->isActive()
            && in_array($profile->status, [ProfileStatus::Draft, ProfileStatus::Rejected, ProfileStatus::Active], true);
    }

    /**
     * Upload, order, caption and delete photos and the horoscope (M11): the owner, while their
     * account is active and the profile isn't suspended or deleted. Live profiles may change
     * photos too — every new photo goes through A04 moderation first.
     */
    public function managePhotos(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->id
            && $user->isActive()
            && ! in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true);
    }
}
