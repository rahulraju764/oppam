<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a profile came to a bureau's commission ledger (B.13): referral code or a managed profile.
 */
enum ReferralSource: string
{
    case Referral = 'REFERRAL';
    case Managed = 'MANAGED';

    public function label(): string
    {
        return match ($this) {
            self::Referral => __('Referral'),
            self::Managed => __('Managed profile'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
