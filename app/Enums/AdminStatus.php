<?php

declare(strict_types=1);

namespace App\Enums;

/** Staff account state (A01). A suspended admin cannot sign in and all their sessions end. */
enum AdminStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }
}
