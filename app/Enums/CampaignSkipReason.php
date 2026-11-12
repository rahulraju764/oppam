<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a frozen recipient was not sent the campaign when its turn came (P8.3b1). */
enum CampaignSkipReason: string
{
    /** Account closed, suspended or deleted, or the profile is no longer ACTIVE. */
    case Inactive = 'INACTIVE';
    /** Switched "Offers and announcements" off on this channel. */
    case OptedOut = 'OPTED_OUT';
    /** The channel can no longer reach the member: email or phone removed / no longer verified since submit. */
    case Unreachable = 'UNREACHABLE';
    /** The campaign was cancelled before this member's turn. */
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => __('No longer active'),
            self::OptedOut => __('Opted out'),
            self::Unreachable => __('No verified email / phone'),
            self::Cancelled => __('Cancelled before sending'),
        };
    }
}
