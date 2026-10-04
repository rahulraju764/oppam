<?php

declare(strict_types=1);

namespace App\Enums;

/** Search result orders (M04). */
enum SearchSort: string
{
    case Relevance = 'relevance';
    case Newest = 'newest';
    case LastActive = 'active';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Relevance => __('Best match'),
            self::Newest => __('Newest'),
            self::LastActive => __('Last active'),
            self::Verified => __('Verified first'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
