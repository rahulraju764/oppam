<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A commission ledger row (B.10).
 */
enum ReferralStatus: string
{
    case Pending = 'PENDING';
    case Earned = 'EARNED';
    case Paid = 'PAID';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Earned => __('Earned'),
            self::Paid => __('Paid'),
            self::Void => __('Void'),
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
            self::Pending => 'muted',
            self::Earned => 'warning',
            self::Paid => 'success',
            self::Void => 'danger',
        };
    }
}
