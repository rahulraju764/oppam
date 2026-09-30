<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Member / broker password rule (M01; docs/decisions.md 2026-09-29): at least 8 characters with
 * a letter and a number, plus the breached-password check in production. Staff keep the stricter
 * Password::defaults() (12+, mixed case). Most members sign in by OTP; this keeps the password
 * usable for parents typing on a phone.
 */
final class MemberPassword
{
    public static function rule(): Password
    {
        $rule = Password::min(8)->letters()->numbers();

        return app()->isProduction() ? $rule->uncompromised() : $rule;
    }
}
