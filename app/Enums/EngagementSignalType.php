<?php

declare(strict_types=1);

namespace App\Enums;

/** Kinds of engagement abuse signal (PRD A13 "Engagement abuse monitors"). More arrive with their guards. */
enum EngagementSignalType: string
{
    case LikeSpam = 'LIKE_SPAM';

    public function label(): string
    {
        return match ($this) {
            self::LikeSpam => __('Like spam'),
        };
    }
}
