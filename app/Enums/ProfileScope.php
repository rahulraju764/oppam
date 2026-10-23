<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which bureau profiles a staff login sees (B.8): all, or only those assigned to them (R-M13-20).
 */
enum ProfileScope: string
{
    case All = 'ALL';
    case Assigned = 'ASSIGNED';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All profiles'),
            self::Assigned => __('Assigned only'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
