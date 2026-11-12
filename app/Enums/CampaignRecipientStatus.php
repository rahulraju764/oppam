<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One member in a campaign's frozen audience (P8.3b1). PENDING until its batch reaches it; then
 * SENT (handed to the channel), SKIPPED (re-checked at send and no longer eligible — see
 * CampaignSkipReason) or FAILED (the channel threw). A row leaves PENDING exactly once.
 */
enum CampaignRecipientStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
