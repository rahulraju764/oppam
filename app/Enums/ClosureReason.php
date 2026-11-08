<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a member closes their account (PRD M14). "Found my match on Oppam" is offered in the form but
 * never closes the account: it starts the M16 story, which hides the profile instead (owner
 * 2026-11-07 (3)). CloseAccount refuses it.
 */
enum ClosureReason: string
{
    case MarriedViaOppam = 'MARRIED_VIA_OPPAM';
    case MarriedElsewhere = 'MARRIED_ELSEWHERE';
    case NotInterested = 'NOT_INTERESTED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::MarriedViaOppam => __('I found my match on Oppam'),
            self::MarriedElsewhere => __('I got married (not through Oppam)'),
            self::NotInterested => __('I’m not looking any more'),
            self::Other => __('Another reason'),
        };
    }

    public function closesAccount(): bool
    {
        return $this !== self::MarriedViaOppam;
    }

    /** @return array<string, string> value => label, for the form */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
