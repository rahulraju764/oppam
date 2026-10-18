<?php

declare(strict_types=1);

namespace App\Enums;

/** Who raised a message flag (PRD A13): ContentSafety as it was sent, or a member's report. */
enum MessageFlagSource: string
{
    case Auto = 'AUTO';
    case Report = 'REPORT';

    public function label(): string
    {
        return match ($this) {
            self::Auto => __('Automatic'),
            self::Report => __('Member report'),
        };
    }
}
