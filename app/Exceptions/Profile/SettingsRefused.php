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

    public static function notHideable(): self
    {
        return new self(__('Only a live profile can be hidden. Profiles waiting for review are not shown to anyone yet.'));
    }

    public static function notHidden(): self
    {
        return new self(__('Your profile isn’t hidden.'));
    }

    public static function exportInProgress(): self
    {
        return new self(__('Your data is already being prepared. We’ll tell you as soon as it’s ready.'));
    }

    public static function exportTooSoon(): self
    {
        return new self(__('You can ask for a copy of your data once a day. Your latest copy is listed below.'));
    }

    public static function closeReasonStartsStory(): self
    {
        return new self(__('Congratulations! Share your story instead — your profile is hidden, not deleted.'));
    }

    public static function storyTooSoon(): self
    {
        return new self(__('You’ve sent several stories recently. Please try again tomorrow.'));
    }

    public static function incognitoNeedsPlan(): self
    {
        return new self(__('Incognito browsing comes with the Diamond plan.'));
    }
}
