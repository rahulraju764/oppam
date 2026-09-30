<?php

declare(strict_types=1);

namespace App\Enums;

/** Who may see the verified phone (PRD §7.2 privacy_settings). */
enum PhoneVisibility: string
{
    case PremiumOnly = 'PREMIUM_ONLY';
    case AcceptedOnly = 'ACCEPTED_ONLY';
    case Hidden = 'HIDDEN';

    public function label(): string
    {
        return match ($this) {
            self::PremiumOnly => __('Premium members only'),
            self::AcceptedOnly => __('Accepted matches only'),
            self::Hidden => __('Hidden'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
