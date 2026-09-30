<?php

declare(strict_types=1);

namespace App\Enums;

/** M02 step 2 employer type. */
enum EmployerType: string
{
    case Government = 'GOVERNMENT';
    case Private = 'PRIVATE';
    case Business = 'BUSINESS';
    case SelfEmployed = 'SELF_EMPLOYED';
    case NotWorking = 'NOT_WORKING';

    public function label(): string
    {
        return match ($this) {
            self::Government => __('Government'),
            self::Private => __('Private'),
            self::Business => __('Business'),
            self::SelfEmployed => __('Self-employed'),
            self::NotWorking => __('Not working'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
