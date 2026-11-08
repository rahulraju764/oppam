<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/** A change on the /settings Account tab was refused (M14, P8.2b); the message is safe to show the member. */
final class AccountChangeRefused extends RuntimeException
{
    public static function confirmCurrentNumberFirst(): self
    {
        return new self(__('Confirm the code sent to your current number first.'));
    }

    public static function sameNumber(): self
    {
        return new self(__('That is already your mobile number.'));
    }

    public static function numberUnavailable(): self
    {
        return new self(__('This number can’t be used. Try another one, or contact our support team.'));
    }

    public static function wrongPassword(): self
    {
        return new self(__('Your current password isn’t right.'));
    }

    public static function passwordLocked(int $seconds): self
    {
        return new self(__('Too many tries. Please wait :minutes minutes and try again.', ['minutes' => max(1, (int) ceil($seconds / 60))]));
    }

    public static function samePassword(): self
    {
        return new self(__('Choose a password different from your current one.'));
    }

    public static function emailUnavailable(): self
    {
        return new self(__('This email address can’t be used. Try another one.'));
    }

    public static function sameEmail(): self
    {
        return new self(__('That is already your verified email address.'));
    }

    public static function tooManyEmails(): self
    {
        return new self(__('You’ve asked for several links today. Please try again tomorrow.'));
    }

    public static function linkInvalid(): self
    {
        return new self(__('This link has expired or was already used. Ask for a new one in Settings.'));
    }

    public static function resetLinkInvalid(): self
    {
        return new self(__('This reset link has expired or was already used. Ask for a new one.'));
    }

    public static function linkForAnotherAccount(): self
    {
        return new self(__('This link is for a different account. Sign out, then open the link again.'));
    }
}
