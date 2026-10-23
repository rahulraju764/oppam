<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bureau login's role (B.2). The permission ceiling of each role is BureauPermission::ceiling().
 */
enum BureauRole: string
{
    case Owner = 'OWNER';
    case Manager = 'MANAGER';
    case DataEntry = 'DATA_ENTRY';
    case Telecaller = 'TELECALLER';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::DataEntry => __('Data entry'),
            self::Telecaller => __('Telecaller'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
