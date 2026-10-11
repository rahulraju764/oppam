<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Domain\Engagement\Connections;
use App\Models\Profile;
use App\Models\User;

/**
 * How a member's name is shown (M03, M06): the owner sees the full name, and so does a member
 * connected to them by an accepted interest ("Accept … reveals last name"); everyone else sees
 * the first name and the last name's initial ("Anna T.").
 */
final class ProfileNames
{
    public function __construct(private readonly Connections $connections) {}

    public function forViewer(Profile $profile, ?User $viewer): string
    {
        if ($viewer !== null && $profile->user_id !== null && $profile->user_id === $viewer->id) {
            return $profile->fullName();
        }

        $own = $viewer?->profile;
        // Viewer first: Connections memoises per first profile, so a page of cards is one query.
        if ($own !== null && $this->connections->isConnected($own, $profile)) {
            return $profile->fullName();
        }

        $initial = $profile->last_name !== null && $profile->last_name !== '' ? ' '.mb_substr($profile->last_name, 0, 1).'.' : '';

        return $profile->first_name.$initial;
    }
}
