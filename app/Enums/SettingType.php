<?php

declare(strict_types=1);

namespace App\Enums;

/** The value type of an admin-editable setting (A15): stored as JSON, returned typed. */
enum SettingType: string
{
    case Integer = 'INTEGER';
    case Boolean = 'BOOLEAN';
    case Text = 'TEXT';

    public function cast(mixed $value): int|bool|string
    {
        return match ($this) {
            self::Integer => (int) $value,
            self::Boolean => (bool) $value,
            self::Text => (string) $value,
        };
    }
}
