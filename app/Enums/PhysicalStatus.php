<?php

declare(strict_types=1);

namespace App\Enums;

/** PRD §7.2 / M02 step 1. */
enum PhysicalStatus: string
{
    case Normal = 'NORMAL';
    case PhysicallyChallenged = 'PHYSICALLY_CHALLENGED';

    public function label(): string
    {
        return match ($this) {
            self::Normal => __('Normal'),
            self::PhysicallyChallenged => __('Physically challenged'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
