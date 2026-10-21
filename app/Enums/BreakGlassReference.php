<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a break-glass read is for (PRD A13 "mandatory reason + linked case or legal request"): an A13
 * flagged message of that conversation, or a legal / law-enforcement request by its reference number.
 * A10 abuse cases join in P6.2.
 */
enum BreakGlassReference: string
{
    case Flag = 'FLAG';
    case Legal = 'LEGAL';

    public function label(): string
    {
        return match ($this) {
            self::Flag => __('Flagged message in this conversation'),
            self::Legal => __('Legal / law-enforcement request'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
