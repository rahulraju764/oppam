<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Models\Profile;
use App\Models\User;

/**
 * How a member's name is shown (M03): the owner sees the full name; everyone else sees the
 * first name and the last name's initial ("Anna T.") until an interest is accepted (P3.4).
 */
final class ProfileNames
{
    public function forViewer(Profile $profile, ?User $viewer): string
    {
        if ($viewer !== null && $profile->user_id !== null && $profile->user_id === $viewer->id) {
            return $profile->fullName();
        }

        $initial = $profile->last_name !== null && $profile->last_name !== '' ? ' '.mb_substr($profile->last_name, 0, 1).'.' : '';

        return $profile->first_name.$initial;
    }
}
