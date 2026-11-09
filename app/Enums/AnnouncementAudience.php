<?php

declare(strict_types=1);

namespace App\Enums;

/** Who sees an A14 announcement banner — the same audiences as A08 ad slots (P8.1b). */
enum AnnouncementAudience: string
{
    case All = 'ALL';
    case FreeOnly = 'FREE_ONLY';
    case Paid = 'PAID';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Every member'),
            self::FreeOnly => __('Free members only'),
            self::Paid => __('Paid members only'),
        };
    }

    public function includes(bool $isPremium): bool
    {
        return match ($this) {
            self::All => true,
            self::FreeOnly => ! $isPremium,
            self::Paid => $isPremium,
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
