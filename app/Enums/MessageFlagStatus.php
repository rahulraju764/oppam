<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A message flag's place in the A13 queue: PENDING waits for a moderator; ESCALATED still waits,
 * for a super admin (as A04 escalations); DISMISSED and ACTIONED are decided.
 */
enum MessageFlagStatus: string
{
    case Pending = 'PENDING';
    case Escalated = 'ESCALATED';
    case Dismissed = 'DISMISSED';
    case Actioned = 'ACTIONED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Escalated => __('Escalated'),
            self::Dismissed => __('Dismissed'),
            self::Actioned => __('Action taken'),
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Escalated;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Escalated->value];
    }
}
