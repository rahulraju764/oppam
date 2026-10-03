<?php

declare(strict_types=1);

namespace App\Enums;

/** Account state (PRD §7.2). Only ACTIVE users may sign in (R-M01-5). */
enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Banned = 'BANNED';
    case Deleted = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
            self::Banned => __('Banned'),
            self::Deleted => __('Deleted'),
        };
    }

    /** <x-ui.badge> variant (the badge text says the status; colour is never the only signal). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Banned, self::Deleted => 'muted',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
