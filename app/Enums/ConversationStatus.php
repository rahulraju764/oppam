<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A chat thread's state (PRD §7.2, M07): OPEN; FROZEN by a block or moderation (readable, no
 * sending); CLOSED for good (the participants can no longer even subscribe to it).
 */
enum ConversationStatus: string
{
    case Open = 'OPEN';
    case Frozen = 'FROZEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Frozen => __('Frozen'),
            self::Closed => __('Closed'),
        };
    }
}
