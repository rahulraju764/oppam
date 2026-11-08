<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Domain\Matching\PreferenceMatcher;
use App\Models\Profile;

/**
 * The member's contact filter (PRD M14 "only members matching my partner preferences can send me
 * interests"; R-M06-3): does it keep this viewer out? THE place that answers it — SendInterest
 * refuses the interest, ContactAccessPolicy the phone number, and the result cards (M04) show the
 * profile with "Send Interest" switched off. Reads the target's privacySetting and
 * partnerPreference relations (eager-load them for a list).
 */
final class ContactFilter
{
    public function __construct(private readonly PreferenceMatcher $matcher) {}

    public function excludes(Profile $viewer, Profile $target): bool
    {
        return ($target->privacySetting->contact_filter_enabled ?? false)
            && ! $this->matcher->meetsAll($viewer, $target->partnerPreference);
    }
}
