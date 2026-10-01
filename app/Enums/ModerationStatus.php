<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a moderation item is (PRD §11 A04): waiting (OPEN, or ESCALATED to a senior), or decided
 * (APPROVED / REJECTED / CHANGES_REQUESTED). Decisions are made in P1.6.
 */
enum ModerationStatus: string
{
    case Open = 'OPEN';
    case Escalated = 'ESCALATED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case ChangesRequested = 'CHANGES_REQUESTED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Waiting'),
            self::Escalated => __('Escalated'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::ChangesRequested => __('Changes requested'),
        };
    }

    public function isPending(): bool
    {
        return $this === self::Open || $this === self::Escalated;
    }

    /** @return list<self> */
    public static function pending(): array
    {
        return [self::Open, self::Escalated];
    }
}
