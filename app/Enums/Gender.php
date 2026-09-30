<?php

declare(strict_types=1);

namespace App\Enums;

/** Profile gender (PRD §7.2). */
enum Gender: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';

    public function label(): string
    {
        return match ($this) {
            self::Male => __('Male'),
            self::Female => __('Female'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
