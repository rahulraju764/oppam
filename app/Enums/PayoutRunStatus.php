<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A payout run (A07, R-A07-4).
 */
enum PayoutRunStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Paid = 'PAID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Approved => __('Approved'),
            self::Paid => __('Paid'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }

    /** <x-ui.badge> variant (the label is always shown too). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'default',
            self::Paid => 'success',
        };
    }
}
