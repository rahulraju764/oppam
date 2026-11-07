<?php

declare(strict_types=1);

namespace App\Exceptions\Profile;

use RuntimeException;

/** A /settings change was refused (M14); the message is safe to show the member. */
final class SettingsRefused extends RuntimeException
{
    public static function tooManyChanges(): self
    {
        return new self(__('You’ve changed your settings many times in the last hour. Please try again a little later — making them more private always works.'));
    }

    public static function tooManySaves(): self
    {
        return new self(__('You’ve saved your settings many times in the last hour. Please try again a little later.'));
    }

    public static function incognitoNeedsPlan(): self
    {
        return new self(__('Incognito browsing comes with the Diamond plan.'));
    }
}
