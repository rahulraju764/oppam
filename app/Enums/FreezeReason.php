<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a conversation is FROZEN (R-M07-5, PRD A13 / M09): a moderator froze it, or the sender was
 * reported from chat by `chat.auto_freeze_reports` different members (automatic, pending review).
 * A block freezes the pair's thread in P6.2.
 */
enum FreezeReason: string
{
    case Moderation = 'MODERATION';
    case Reports = 'REPORTS';

    public function label(): string
    {
        return match ($this) {
            self::Moderation => __('Frozen by a moderator'),
            self::Reports => __('Frozen automatically after several reports'),
        };
    }
}
