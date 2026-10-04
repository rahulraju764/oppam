<?php

declare(strict_types=1);

namespace App\Enums;

/** How an impersonation session ended (A01). */
enum ImpersonationEndReason: string
{
    case Ended = 'ENDED';            // the admin pressed "End session"
    case SignedOut = 'SIGNED_OUT';   // the admin used the member's "Log out"
    case Expired = 'EXPIRED';        // the 30 minutes ran out, or the member's account stopped being usable
    case Replaced = 'REPLACED';      // the same admin started another one
    case NotUsed = 'NOT_USED';       // the handoff link was never opened
    case Revoked = 'REVOKED';        // the member's account changed (suspended, signed out everywhere…) or the admin lost access

    public function label(): string
    {
        return match ($this) {
            self::Ended => __('Ended by the admin'),
            self::SignedOut => __('Signed out'),
            self::Expired => __('Time limit reached'),
            self::Replaced => __('Replaced by a new session'),
            self::NotUsed => __('Link not used'),
            self::Revoked => __('Access withdrawn'),
        };
    }
}
