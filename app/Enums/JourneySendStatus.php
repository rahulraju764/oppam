<?php

declare(strict_types=1);

namespace App\Enums;

/** One `journey_sends` ledger row (P8.3b2): what happened to a member's journey step. */
enum JourneySendStatus: string
{
    /** Handed to the notification queue (preferences, quiet hours and the block check apply there too). */
    case Sent = 'SENT';
    /** The member switched this reminder off on the journey's channel. */
    case SkippedOptedOut = 'SKIPPED_OPTED_OUT';
    /** The channel can't reach the member (no verified email / phone), or the other member is blocked. */
    case SkippedUnreachable = 'SKIPPED_UNREACHABLE';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Sent'),
            self::SkippedOptedOut => __('Skipped: opted out'),
            self::SkippedUnreachable => __('Skipped: unreachable'),
            self::Failed => __('Failed'),
        };
    }
}
