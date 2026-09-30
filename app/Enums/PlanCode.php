<?php

declare(strict_types=1);

namespace App\Enums;

/** Membership plans (PRD §7.3). FREE is a real row holding the Free limits, but never purchasable. */
enum PlanCode: string
{
    case Free = 'FREE';
    case Silver = 'SILVER';
    case Gold = 'GOLD';
    case Diamond = 'DIAMOND';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Silver => __('Silver'),
            self::Gold => __('Gold'),
            self::Diamond => __('Diamond'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
