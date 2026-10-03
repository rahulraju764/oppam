<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bulk actions on the A03 member list. There is deliberately NO delete here — A03: "no bulk
 * delete"; deletion is one member at a time with the typed code.
 */
enum MemberBulkAction: string
{
    case Suspend = 'SUSPEND';
    case Activate = 'ACTIVATE';
    case Notify = 'NOTIFY';

    public function label(): string
    {
        return match ($this) {
            self::Suspend => __('Suspend'),
            self::Activate => __('Reactivate'),
            self::Notify => __('Send a message (email)'),
        };
    }

    /** The admin permission the action needs. */
    public function permission(): string
    {
        return match ($this) {
            self::Suspend, self::Activate => 'members.suspend',
            self::Notify => 'members.edit',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
