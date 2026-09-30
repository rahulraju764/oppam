<?php

declare(strict_types=1);

namespace App\Enums;

/** Chovva / papa dosham answer (M02 step 1: yes / no / don't know). */
enum Dosham: string
{
    case Yes = 'YES';
    case No = 'NO';
    case DontKnow = 'DONT_KNOW';

    public function label(): string
    {
        return match ($this) {
            self::Yes => __('Yes'),
            self::No => __('No'),
            self::DontKnow => __("Don't know"),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
