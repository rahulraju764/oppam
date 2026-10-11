<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Engagement\Connections;
use App\Enums\AccessRequestType;
use App\Enums\PhotoVisibility;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

/**
 * THE rule for who sees a profile's photos clearly (M11, privacy_settings.photo_visibility).
 * Everyone else gets the blurred conversion (PhotoUrls). Owner: always. Guests: never.
 * ALL_MEMBERS: any active member. PREMIUM_ONLY: viewers with a paid plan. ACCEPTED_ONLY: members
 * connected by an accepted interest. ON_REQUEST: those, plus anyone whose photo request the owner
 * approved (Connections, P3.4).
 */
final class PhotoAccess
{
    public function __construct(private readonly Connections $connections) {}

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

    /** An accepted interest between the two (either direction), or — for ON_REQUEST — an approved photo request. */
    private function isConnected(Profile $owner, Profile $viewer): bool
    {
        // Viewer first: Connections memoises per first profile, so a page of cards is one query.
        return $this->connections->isConnected($viewer, $owner)
            || ($this->visibility($owner) === PhotoVisibility::OnRequest
                && $this->connections->hasGrant(AccessRequestType::Photo, $owner, $viewer));
    }
}
