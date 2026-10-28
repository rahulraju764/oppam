<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One row of a bulk-import batch (B.9).
 */
enum ImportRowStatus: string
{
    case Valid = 'VALID';
    case Warning = 'WARNING';
    case Error = 'ERROR';
    case Imported = 'IMPORTED';
    case Skipped = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::Valid => __('Valid'),
            self::Warning => __('Warning'),
            self::Error => __('Error'),
            self::Imported => __('Imported'),
            self::Skipped => __('Skipped'),
        };
    }

    /** <x-ui.badge> variant (explicit map — never built from data). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Valid, self::Imported => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Skipped => 'muted',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
