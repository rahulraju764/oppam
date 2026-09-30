<?php

declare(strict_types=1);

namespace App\Enums;

/** Account type on the web guard (PRD §8.1). Brokers and their staff are users too. */
enum UserRole: string
{
    case Member = 'MEMBER';
    case Broker = 'BROKER';

    public function label(): string
    {
        return match ($this) {
            self::Member => __('Member'),
            self::Broker => __('Broker'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
