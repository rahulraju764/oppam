<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A08 step doesn't fit the row's current state (shown to the admin as is). */
final class ContentRefused extends RuntimeException
{
    public static function notAllowedFrom(string $status): self
    {
        return new self(__('This step isn’t available while the item is :status.', ['status' => $status]));
    }

    public static function blocked(string $why): self
    {
        return new self($why);
    }

    public static function scheduleInPast(): self
    {
        return new self(__('Choose a time in the future.'));
    }

    public static function noPendingChanges(): self
    {
        return new self(__('There are no waiting changes.'));
    }

    public static function alreadyWithdrawn(): self
    {
        return new self(__('This story has already been withdrawn.'));
    }

    public static function changedMeanwhile(): self
    {
        return new self(__('Someone changed this item while you were looking at it. Open it again to see the latest version.'));
    }

    public static function withdrawnRow(): self
    {
        return new self(__('This story was withdrawn. Restore it to draft before changing it.'));
    }

    public static function tooManyChanges(): self
    {
        return new self(__('You’ve changed this too many times. Please try again later.'));
    }

    public static function cannotCreate(): self
    {
        return new self(__('These are fixed page sections and can’t be added.'));
    }

    public static function publishedVersion(): self
    {
        return new self(__('This version has been published and members have agreed to it, so it can’t be changed. Start a new version instead.'));
    }

    public static function liveLegalVersion(): self
    {
        return new self(__('The live version can’t be taken off the site. Publish a newer version; this one is archived automatically.'));
    }
}
