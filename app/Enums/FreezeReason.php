<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a conversation is FROZEN (R-M07-5, PRD A13 / M09): a moderator froze it, or the sender was
 * reported from chat by `chat.auto_freeze_reports` different members (automatic, pending review).
 * BLOCK: one of the pair blocked the other (R-M06-4) — the thread opens again only when no block
 * is left either way (UnblockProfile).
 */
enum FreezeReason: string
{
    case Moderation = 'MODERATION';
    case Reports = 'REPORTS';
    case Block = 'BLOCK';

    public function label(): string
    {
        return match ($this) {
            self::Moderation => __('Frozen by a moderator'),
            self::Reports => __('Frozen automatically after several reports'),
            self::Block => __('Frozen because one member blocked the other'),
        };
    }
}
