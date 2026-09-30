<?php

declare(strict_types=1);

namespace App\Support\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Status/type columns are strings cast to PHP backed enums (CLAUDE.md "Enums"). This adds a
 * CHECK constraint listing the enum's values, so the database refuses a value the code would
 * never write (laravel-patterns.md: "constraints enforce rules the app also checks").
 * MySQL 8.0.16+ and MariaDB 10.2+ enforce CHECK; other drivers are skipped.
 */
final class EnumCheck
{
    /** @param class-string<BackedEnum> $enum */
    public static function add(string $table, string $column, string $enum, bool $nullable = false): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! preg_match('/^[a-z_]+$/', $table.$column)) {
            throw new InvalidArgumentException('Unexpected table/column name.');
        }

        $values = collect($enum::cases())
            ->map(fn (BackedEnum $case): string => DB::getPdo()->quote((string) $case->value))
            ->implode(', ');

        $condition = "`{$column}` IN ({$values})".($nullable ? " OR `{$column}` IS NULL" : '');

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `chk_{$table}_{$column}` CHECK ({$condition})");
    }
}
