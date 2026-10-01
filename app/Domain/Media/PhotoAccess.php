<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Enums\PhotoVisibility;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

/**
 * THE rule for who sees a profile's photos clearly (M11, privacy_settings.photo_visibility).
 * Everyone else gets the blurred conversion (PhotoUrls). Owner: always. Guests: never.
 * ALL_MEMBERS: any active member. PREMIUM_ONLY: viewers with a paid plan. ON_REQUEST and
 * ACCEPTED_ONLY need an accepted connection — interests arrive in P3.4, so until then
 * isConnected() is false and those photos stay blurred.
 */
final class PhotoAccess
{
    public function canSeeClearly(Profile $owner, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($owner->user_id !== null && $owner->user_id === $viewer->id) {
            return true;
        }

        if ($viewer->role !== UserRole::Member || ! $viewer->isActive()) {
            return false;
        }

        $viewerProfile = $viewer->profile;

        return match ($this->visibility($owner)) {
            PhotoVisibility::AllMembers => true,
            PhotoVisibility::PremiumOnly => $viewerProfile?->is_premium === true,
            PhotoVisibility::OnRequest, PhotoVisibility::AcceptedOnly => $viewerProfile !== null && $this->isConnected($owner, $viewerProfile),
        };
    }

    public function isOwner(Profile $owner, ?User $viewer): bool
    {
        return $viewer !== null && $owner->user_id !== null && $owner->user_id === $viewer->id;
    }

    private function visibility(Profile $owner): PhotoVisibility
    {
        return $owner->privacySetting->photo_visibility ?? PhotoVisibility::AllMembers;
    }

    /** Accepted interest (or granted photo request) between the two — P3.4 plugs in here. */
    private function isConnected(Profile $owner, Profile $viewer): bool
    {
        return false;
    }
}
