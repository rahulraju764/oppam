<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a viewer may do with a profile's contact details right now (R-M03-2). Only Revealed and
 * CanReveal open anything; every other case explains why not (UI "locked" state).
 */
enum ContactAccess: string
{
    case Revealed = 'REVEALED';            // already revealed before — free to see again
    case CanReveal = 'CAN_REVEAL';         // allowed; revealing uses one contact view
    case NeedsPlan = 'NEEDS_PLAN';         // the viewer's plan has no contact views
    case QuotaUsed = 'QUOTA_USED';         // this month's contact views are used up
    case HiddenByMember = 'HIDDEN';        // phone_visibility = HIDDEN
    case AcceptedOnly = 'ACCEPTED_ONLY';   // only after an accepted interest (P3.4)
    case FilteredOut = 'FILTERED_OUT';     // the member's contact filter excludes the viewer

    public function opens(): bool
    {
        return $this === self::Revealed || $this === self::CanReveal;
    }

    /** The way out is a (better) plan: show a link to the plans page. */
    public function suggestsUpgrade(): bool
    {
        return $this === self::NeedsPlan || $this === self::QuotaUsed;
    }

    public function message(): string
    {
        return match ($this) {
            self::Revealed => __('You have already viewed these contact details.'),
            self::CanReveal => __('Viewing uses one of your monthly contact views.'),
            self::NeedsPlan => __('Upgrade your plan to view contact details.'),
            self::QuotaUsed => __('You have used all your contact views for this month.'),
            self::HiddenByMember => __('This member has chosen not to share contact details.'),
            self::AcceptedOnly => __('This member shares contact details once your interest is accepted.'),
            self::FilteredOut => __('This member only shares contact details with profiles that match their preferences.'),
        };
    }
}
