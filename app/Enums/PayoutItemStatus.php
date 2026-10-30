<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One bureau's line in a payout run.
 */
enum PayoutItemStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case CarriedForward = 'CARRIED_FORWARD';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Paid => __('Paid'),
            self::CarriedForward => __('Carried forward'),
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
            self::Pending => 'warning',
            self::Paid => 'success',
            self::CarriedForward => 'muted',
        };
    }
}
