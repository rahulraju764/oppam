<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\ProfileStatus;
use App\Models\Profile;

/**
 * A member hiding their own profile (M14 "hide, reversible any time"; M16 auto-hide after a success
 * story). One place for the status move so the settings switch and the story flow can't drift:
 * only an ACTIVE profile is hidden, and unhiding returns the status it had. Callers hold the
 * profile row lock and write the audit row. A hidden profile is out of search, matches, featured
 * members and the online roster, because each of those reads only ACTIVE profiles.
 */
final class MemberHiding
{
    /** Hides an ACTIVE profile; returns false (nothing changed) for any other status. */
    public function hide(Profile $profile): bool
    {
        if ($profile->status !== ProfileStatus::Active) {
            return false;
        }

        $profile->previous_status = ProfileStatus::Active;
        $profile->status = ProfileStatus::Hidden;
        $profile->save();

        return true;
    }

    /** Unhides a HIDDEN profile; returns false (nothing changed) for any other status. */
    public function unhide(Profile $profile): bool
    {
        if ($profile->status !== ProfileStatus::Hidden) {
            return false;
        }

        $previous = $profile->previous_status;
        $profile->status = $previous !== null && ! in_array($previous, [ProfileStatus::Hidden, ProfileStatus::Suspended, ProfileStatus::Deleted], true)
            ? $previous
            : ($profile->published_at !== null ? ProfileStatus::Active : ProfileStatus::Draft);
        $profile->previous_status = null;
        $profile->save();

        return true;
    }
}
