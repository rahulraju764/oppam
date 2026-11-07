<?php

declare(strict_types=1);

namespace App\Enums;

/** Who sees an ad (A08): free members, members with a plan in force, or everyone signed in. */
enum AdAudience: string
{
    case FreeOnly = 'FREE_ONLY';
    case Paid = 'PAID';
    case All = 'ALL';

    public function label(): string
    {
        return match ($this) {
            self::FreeOnly => __('Free members only'),
            self::Paid => __('Paid members only'),
            self::All => __('All members'),
        };
    }

    /** @return list<self> the audiences a viewer belongs to */
    public static function for(bool $paid): array
    {
        return [self::All, $paid ? self::Paid : self::FreeOnly];
    }
}
