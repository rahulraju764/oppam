<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Profile\ProfileVisibility;
use App\Enums\HoroscopeVisibility;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Who may open a profile's horoscope file (M11, privacy_settings.horoscope_visibility), and the
 * short-lived signed link they get. Owner: always. ALL_MEMBERS: any active member. ON_REQUEST /
 * ACCEPTED_ONLY: an accepted connection — interests arrive in P3.4, until then no one else.
 * The link only gets a viewer to HoroscopeController, which checks again and audits the view.
 */
final class HoroscopeAccess
{
    public function __construct(private readonly ProfileVisibility $visibility) {}

    public function canView(Profile $owner, ?User $viewer): bool
    {
        if ($viewer === null || ! $viewer->isActive()) {
            return false;
        }

        if ($owner->user_id !== null && $owner->user_id === $viewer->id) {
            return true;
        }

        // Same gate as the profile page itself: ACTIVE, opposite gender, not blocked (M03).
        if ($viewer->role !== UserRole::Member || ! $this->visibility->canView($owner, $viewer)) {
            return false;
        }

        return match ($owner->privacySetting->horoscope_visibility ?? HoroscopeVisibility::AllMembers) {
            HoroscopeVisibility::AllMembers => true,
            HoroscopeVisibility::OnRequest, HoroscopeVisibility::AcceptedOnly => false,   // P3.4
        };
    }

    /** A signed link valid for a few minutes, or null when there is no file or no permission. */
    public function link(Profile $owner, ?User $viewer): ?string
    {
        if (! $owner->hasMedia(Profile::HOROSCOPE) || ! $this->canView($owner, $viewer)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'member.horoscope',
            now()->addMinutes((int) config('oppam.media.signed_url_minutes')),
            ['profile' => $owner->code],
        );
    }
}
