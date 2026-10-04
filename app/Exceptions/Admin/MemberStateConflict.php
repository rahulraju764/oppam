<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A03 action doesn't fit the member's current state (shown to the admin as a toast). */
final class MemberStateConflict extends RuntimeException
{
    public static function notActive(): self
    {
        return new self(__('Only an active account can be suspended.'));
    }

    public static function notSuspended(): self
    {
        return new self(__('This account is not suspended.'));
    }

    public static function notVisible(): self
    {
        return new self(__('Only a live (active) profile can be hidden.'));
    }

    public static function notHidden(): self
    {
        return new self(__('This profile is not hidden.'));
    }

    public static function alreadyDeleted(): self
    {
        return new self(__('This account is already deleted.'));
    }

    public static function notDeleted(): self
    {
        return new self(__('This account is not deleted.'));
    }

    public static function restoreWindowClosed(): self
    {
        return new self(__('The restore window has passed; this account has been (or is about to be) anonymised.'));
    }

    public static function changedMeanwhile(): self
    {
        return new self(__('Someone else changed this profile after you opened it. The form has been reloaded — please check and save again.'));
    }

    public static function phoneNotVerified(): self
    {
        return new self(__('This member has not verified their phone number yet.'));
    }

    public static function beingImpersonated(): self
    {
        return new self(__('Another admin is viewing this account right now. Try again when they have finished.'));
    }

    public static function tooManyStarts(): self
    {
        return new self(__('Too many support sessions started for this member. Please wait a few minutes.'));
    }

    public static function notUnverified(): self
    {
        return new self(__('This member has already verified their phone number.'));
    }

    public static function otpThrottled(string $message): self
    {
        return new self($message);
    }

    public static function deleted(): self
    {
        return new self(__('This account is deleted. Restore it first.'));
    }
}
