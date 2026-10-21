<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A moderator's one-click action on an A13 abuse signal (PRD A13 "rate-limit, warn, suspend"), or
 * dismissing it. RATE_LIMIT pauses everything the member sends — likes, favourites, interests,
 * photo / horoscope requests and chat messages — for 1 h, 24 h or 7 days (decisions 2026-10-18).
 * Each needs its own permission.
 */
enum EngagementSignalDecision: string
{
    case Dismiss = 'DISMISS';
    case RateLimit = 'RATE_LIMIT';
    case Warn = 'WARN';
    case Suspend = 'SUSPEND';

    /** The pause lengths a moderator may choose, in hours. */
    public const RATE_LIMIT_HOURS = [1, 24, 168];

    public function label(): string
    {
        return match ($this) {
            self::Dismiss => __('Dismiss'),
            self::RateLimit => __('Pause sending'),
            self::Warn => __('Warn member'),
            self::Suspend => __('Suspend member'),
        };
    }

    /** The admin permission this decision needs (config/admin-permissions.php). */
    public function permission(): string
    {
        return $this === self::Suspend ? 'members.suspend' : 'chat.moderate';
    }

    public function resultingStatus(): EngagementSignalStatus
    {
        return $this === self::Dismiss ? EngagementSignalStatus::Dismissed : EngagementSignalStatus::Actioned;
    }
}
