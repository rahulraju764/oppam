<?php

declare(strict_types=1);

namespace App\Enums;

/** An A06 reconciliation "investigate" row: open until finance resolves it with a note. */
enum InvestigationStatus: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Resolved => __('Resolved'),
        };
    }
}
