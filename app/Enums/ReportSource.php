<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a member filed a report (PRD M09): from a chat thread (A13 flags + A10 case) or a profile page (A10 case). */
enum ReportSource: string
{
    case Chat = 'CHAT';
    case Profile = 'PROFILE';

    public function label(): string
    {
        return match ($this) {
            self::Chat => __('Chat'),
            self::Profile => __('Profile page'),
        };
    }
}
