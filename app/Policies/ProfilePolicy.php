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
     * DRAFT or was REJECTED ("Edit & resubmit", R-M02-5). Edits of a live profile go through the
     * profile page instead (R-M02-1 / R-M02-4, P1.3).
     */
    public function editWizard(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->id
            && $user->isActive()
            && in_array($profile->status, [ProfileStatus::Draft, ProfileStatus::Rejected], true);
    }
}
