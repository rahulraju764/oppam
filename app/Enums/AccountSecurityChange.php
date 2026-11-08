<?php

declare(strict_types=1);

namespace App\Enums;

/** What changed in a member's sign-in details (M14 Account tab, P8.2b) — the security notice says which. */
enum AccountSecurityChange: string
{
    case PhoneChanged = 'PHONE_CHANGED';
    case EmailVerified = 'EMAIL_VERIFIED';
    case EmailRemoved = 'EMAIL_REMOVED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case PasswordResetByEmail = 'PASSWORD_RESET_BY_EMAIL';

    public function title(): string
    {
        return match ($this) {
            self::PhoneChanged => __('Your mobile number was changed'),
            self::EmailVerified => __('Your email address is verified'),
            self::EmailRemoved => __('Your email address was removed'),
            self::PasswordChanged => __('Your password was changed'),
            self::PasswordResetByEmail => __('Your password was reset'),
        };
    }

    public function body(): string
    {
        return match ($this) {
            self::PhoneChanged => __('You now sign in with your new mobile number. Every other device was signed out.'),
            self::EmailVerified => __('We’ll send account emails to this address from now on.'),
            self::EmailRemoved => __('We won’t send you emails any more. You can add an address again in Settings.'),
            self::PasswordChanged => __('Every other device was signed out.'),
            self::PasswordResetByEmail => __('Your password was reset from an email link. Every other device was signed out.'),
        };
    }
}
