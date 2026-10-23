<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A member report's state: OPEN until its flagged messages are decided; ACTIONED when any of them
 * led to an action, DISMISSED when all were dismissed. A dismissed report doesn't count towards
 * the automatic freeze.
 */
enum ReportStatus: string
{
    case Open = 'OPEN';
    case Actioned = 'ACTIONED';
    case Dismissed = 'DISMISSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Actioned => __('Actioned'),
            self::Dismissed => __('Dismissed'),
        };
    }
}
