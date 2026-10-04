<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Domain\Safety\BlockList;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

/**
 * May this member open that profile page (M03)? The owner always may. Anyone else needs an
 * active member account with a profile of their own, and the target must be ACTIVE (R-M03-3),
 * of the opposite gender (owner decision 2026-10-01) and not blocked either way (R-M03-1). A
 * viewer whose own profile is SUSPENDED sees no one (pending / rejected / hidden viewers may browse).
 * Every "no" is a 404 to the caller — never a hint that the profile exists.
 */
final class ProfileVisibility
{
    public function __construct(private readonly BlockList $blocks) {}

    public function canView(Profile $target, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($this->isOwner($target, $viewer)) {
            return true;
        }

        $own = $viewer->profile;

        return $own !== null
            && $this->canBrowse($viewer)
            && $target->status === ProfileStatus::Active
            && $target->gender !== $own->gender
            && ! $this->blocks->isBlocked($own, $target);
    }

    /**
     * May this member look at other members at all (profile pages, search, lists)? An active
     * member account whose own profile isn't SUSPENDED (owner decision 2026-10-01).
     */
    public function canBrowse(?User $viewer): bool
    {
        $own = $viewer?->profile;

        return $viewer !== null
            && $viewer->role === UserRole::Member
            && $viewer->isActive()
            && $own !== null
            && $own->status !== ProfileStatus::Suspended;
    }

    public function isOwner(Profile $target, ?User $viewer): bool
    {
        return $viewer !== null && $target->user_id !== null && $target->user_id === $viewer->id;
    }
}
