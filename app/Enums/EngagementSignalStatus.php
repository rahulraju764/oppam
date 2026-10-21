<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an A13 abuse signal stands: waiting for a moderator, acted on, or dismissed. */
enum EngagementSignalStatus: string
{
    case Open = 'OPEN';
    case Actioned = 'ACTIONED';
    case Dismissed = 'DISMISSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Waiting'),
            self::Actioned => __('Acted on'),
            self::Dismissed => __('Dismissed'),
        };
    }
}
