<?php

declare(strict_types=1);

namespace App\Enums;

/** Who may see the horoscope (PRD §7.2 privacy_settings). */
enum HoroscopeVisibility: string
{
    case AllMembers = 'ALL_MEMBERS';
    case AcceptedOnly = 'ACCEPTED_ONLY';
    case OnRequest = 'ON_REQUEST';

    public function label(): string
    {
        return match ($this) {
            self::AllMembers => __('All members'),
            self::AcceptedOnly => __('Accepted matches only'),
            self::OnRequest => __('On request'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
