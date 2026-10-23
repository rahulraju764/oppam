<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An A10 abuse case (PRD A10 "aggregated into one case per profile"): OPEN while it waits for a
 * decision — new reports about the same member join it — and CLOSED once an outcome is recorded.
 * The next report after that opens a new case.
 */
enum AbuseCaseStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Closed => __('Closed'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Closed => 'muted',
        };
    }
}
