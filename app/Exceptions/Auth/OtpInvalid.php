<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * The code was wrong, expired or used up (R-M01-2). One message for "no such code" and "wrong
 * code", so it never reveals whether a number has a live code — or an account.
 */
final class OtpInvalid extends RuntimeException
{
    public static function wrongOrExpired(): self
    {
        return new self(__('That code is incorrect or has expired. Check the SMS or ask for a new code.'));
    }

    public static function tooManyAttempts(): self
    {
        return new self(__('Too many wrong attempts. Please ask for a new code.'));
    }
}
