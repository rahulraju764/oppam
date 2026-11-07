<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\Entitlement;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Services\Entitlements\EntitlementService;

/**
 * The two "keep me out of sight" switches of M14 (owner decisions 2026-11-07), in one place:
 *  - hide_from_search (every plan): out of search, matches, alerts and the home page — read as a
 *    column by ProfileSearch / FeaturedMembers;
 *  - incognito (Diamond, Entitlement::Incognito): browse without appearing in "who viewed me".
 *    The stored switch only counts while the plan has it, so it lapses with the plan and comes
 *    back on renewal without the member doing anything.
 */
final class Incognito
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** True when this member's profile views are not recorded. */
    public function isActive(Profile $profile, ?PrivacySetting $privacy = null): bool
    {
        $privacy ??= $profile->privacySetting;

        return $privacy?->incognito === true && $this->entitlements->allows($profile, Entitlement::Incognito);
    }

    /** May the plan switch incognito on? (The Privacy tab shows an upgrade note otherwise.) */
    public function isAvailable(Profile $profile): bool
    {
        return $this->entitlements->allows($profile, Entitlement::Incognito);
    }

    /**
     * Kept off the online roster (presence.online): online status switched off, hidden from search
     * (as before the split, so nobody who was hidden starts to show) or incognito in force.
     */
    public function hidesPresence(Profile $profile, ?PrivacySetting $privacy): bool
    {
        if ($privacy === null) {
            return false;
        }

        return ! $privacy->show_online_status || $privacy->hide_from_search || $this->isActive($profile, $privacy);
    }
}
