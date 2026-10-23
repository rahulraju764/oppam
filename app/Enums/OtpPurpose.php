<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a one-time code was sent (M01). Codes for one purpose never satisfy another. */
enum OtpPurpose: string
{
    case Register = 'REGISTER';
    case Login = 'LOGIN';
    case PasswordReset = 'PASSWORD_RESET';
    case ChangePhone = 'CHANGE_PHONE';
    case BrokerDevice = 'BROKER_DEVICE';   // §8.1: bureau Owner / Manager on a new device

    public function label(): string
    {
        return match ($this) {
            self::Register => __('Registration'),
            self::Login => __('Login'),
            self::PasswordReset => __('Password reset'),
            self::ChangePhone => __('Change mobile number'),
            self::BrokerDevice => __('New device check'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
