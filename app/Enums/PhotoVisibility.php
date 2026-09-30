<?php

declare(strict_types=1);

namespace App\Enums;

/** Who may see a profile's photos (PRD §7.2 privacy_settings, M11). */
enum PhotoVisibility: string
{
    case AllMembers = 'ALL_MEMBERS';
    case PremiumOnly = 'PREMIUM_ONLY';
    case OnRequest = 'ON_REQUEST';
    case AcceptedOnly = 'ACCEPTED_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::AllMembers => __('All members'),
            self::PremiumOnly => __('Premium members only'),
            self::OnRequest => __('On request'),
            self::AcceptedOnly => __('Accepted matches only'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
