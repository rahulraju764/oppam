<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A consent dispute's outcome (§11A B.12 "Consent disputes", R-A07-10): OPEN while A07 looks into
 * it (the profile stays suspended), UPHELD — the client never agreed; counts towards the bureau's
 * creation pause — or DISMISSED (the profile returns to the status it had).
 */
enum ConsentDisputeStatus: string
{
    case Open = 'OPEN';
    case Upheld = 'UPHELD';
    case Dismissed = 'DISMISSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Upheld => __('Upheld'),
            self::Dismissed => __('Dismissed'),
        };
    }

    /** <x-ui.badge> variant (the label is always shown too). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Upheld => 'danger',
            self::Dismissed => 'muted',
        };
    }
}
