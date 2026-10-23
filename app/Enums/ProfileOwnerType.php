<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who runs a profile (B.6): the member themself, or a bureau until the client claims it.
 */
enum ProfileOwnerType: string
{
    case Self = 'SELF';
    case BrokerManaged = 'BROKER_MANAGED';

    public function label(): string
    {
        return match ($this) {
            self::Self => __('Self-registered'),
            self::BrokerManaged => __('Bureau-managed'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
