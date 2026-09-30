<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/**
 * A failed admin sign-in step. The message is safe to show: it never says whether an email
 * belongs to an admin (no enumeration). $retryAfterSeconds is set for lockouts.
 */
final class AdminAuthenticationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self(__('These details don’t match an active admin account.'));
    }

    public static function lockedOut(int $seconds): self
    {
        return new self(__('Too many attempts. Try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)]), $seconds);
    }

    public static function invalidCode(): self
    {
        return new self(__('That code is not valid. Check your authenticator app and try again.'));
    }

    public static function accountLocked(): self
    {
        return new self(__('Your account has been locked after too many wrong codes. A super admin has been alerted.'));
    }

    public static function loginExpired(): self
    {
        return new self(__('Your sign-in has expired. Please enter your email and password again.'));
    }
}
