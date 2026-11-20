<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The detail pages that carry an A12 per-entity timeline (P9.2a), each with the permission its
 * page needs — the timeline is part of that page, so it needs the same.
 */
enum TimelineEntity: string
{
    case Member = 'member';
    case Broker = 'broker';
    case Order = 'order';

    public function permission(): string
    {
        return match ($this) {
            self::Member => 'members.view',
            self::Broker => 'brokers.view',
            self::Order => 'billing.view',
        };
    }
}
