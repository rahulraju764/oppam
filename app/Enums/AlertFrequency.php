<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Saved search alert frequency (M04).
 */
enum AlertFrequency: string
{
    case Off = 'OFF';
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';

    public function label(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Daily => __('Daily digest'),
            self::Weekly => __('Weekly digest'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
