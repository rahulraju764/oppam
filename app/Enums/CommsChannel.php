<?php

declare(strict_types=1);

namespace App\Enums;

/** A14 message channel: email, SMS (DLT template) or the in-app bell. */
enum CommsChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Sms => __('SMS'),
            self::InApp => __('In-app'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
