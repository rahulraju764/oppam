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

    public static function cannotCreate(): self
    {
        return new self(__('These are fixed page sections and can’t be added.'));
    }
}
