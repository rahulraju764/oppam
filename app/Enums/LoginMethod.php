<?php

declare(strict_types=1);

namespace App\Enums;

/** How a member or broker signed in (M01, login_events). */
enum LoginMethod: string
{
    case Otp = 'OTP';
    case Password = 'PASSWORD';
    case Registration = 'REGISTRATION';
    case PasswordReset = 'PASSWORD_RESET';

    public function label(): string
    {
        return match ($this) {
            self::Otp => __('One-time code'),
            self::Password => __('Password'),
            self::Registration => __('Registration'),
            self::PasswordReset => __('Password reset'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
