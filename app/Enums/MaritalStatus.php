<?php

declare(strict_types=1);

namespace App\Enums;

/** PRD §7.2 / M02 step 1. */
enum MaritalStatus: string
{
    case NeverMarried = 'NEVER_MARRIED';
    case Divorced = 'DIVORCED';
    case Widowed = 'WIDOWED';
    case AwaitingDivorce = 'AWAITING_DIVORCE';
    case Annulled = 'ANNULLED';

    public function label(): string
    {
        return match ($this) {
            self::NeverMarried => __('Never married'),
            self::Divorced => __('Divorced'),
            self::Widowed => __('Widowed'),
            self::AwaitingDivorce => __('Awaiting divorce'),
            self::Annulled => __('Annulled'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
