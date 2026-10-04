<?php

declare(strict_types=1);

namespace App\Enums;

/** The My Matches tabs (PRD M05). The first four are the 2 × 2 funnel counters. */
enum MatchTab: string
{
    case All = 'all';
    case Unviewed = 'unviewed';
    case Viewed = 'viewed';
    case Mutual = 'mutual';
    case New = 'new';
    case NearMe = 'near';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All Matches'),
            self::Unviewed => __('Yet to be Viewed'),
            self::Viewed => __('Viewed'),
            self::Mutual => __('Mutual Matches'),
            self::New => __('New Matches'),
            self::NearMe => __('Near Me'),
            self::Premium => __('Premium'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::All => 'fa-heart',
            self::Unviewed => 'fa-eye-slash',
            self::Viewed => 'fa-eye',
            self::Mutual => 'fa-exchange',
            self::New => 'fa-star',
            self::NearMe => 'fa-map-marker',
            self::Premium => 'fa-diamond',
        };
    }

    /** @return list<self> */
    public static function funnel(): array
    {
        return [self::All, self::Unviewed, self::Viewed, self::Mutual];
    }
}
