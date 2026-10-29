<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a bureau's bulk imports are paused (§11A B.9 auto-pause, R-M13-32; R-A07-9 admin pause).
 * Single-profile creation is never affected.
 */
enum ImportsPauseReason: string
{
    case Auto = 'AUTO';     // > threshold % of a batch's decided profiles were rejected at A04
    case Admin = 'ADMIN';   // paused from A07 (P7.8)

    public function label(): string
    {
        return match ($this) {
            self::Auto => __('Paused automatically: too many profiles from one batch were rejected at review'),
            self::Admin => __('Paused by the Oppam team'),
        };
    }
}
