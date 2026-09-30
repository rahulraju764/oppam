<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * A member sign-in was refused. invalidCredentials() is deliberately the same text whether the
 * login id exists or not (R-M01-4). accountBlocked() is only raised after the member has proved
 * who they are (right password or code), so it reveals nothing to a stranger (R-M01-5).
 */
final class LoginFailed extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self(__('The login details you entered are incorrect.'));
    }

    public static function lockedOut(int $seconds): self
    {
        return new self(__('Too many attempts. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil($seconds / 60))]));
    }

    public static function accountBlocked(): self
    {
        return new self(__('This account is not active. Please contact our support team for help.'));
    }
}
