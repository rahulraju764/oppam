<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** A contact-inbox step doesn't fit the message's current state (shown to the admin as is). */
final class ContactRefused extends RuntimeException
{
    public static function isSpam(): self
    {
        return new self(__('This message is in the spam tab. Mark it as not spam first.'));
    }

    public static function alreadyConverted(): self
    {
        return new self(__('This message is already a support ticket. Continue there.'));
    }

    public static function changedMeanwhile(): self
    {
        return new self(__('Someone changed this message while you were looking at it. Open it again to see the latest version.'));
    }

    public static function assigneeCannotRespond(): self
    {
        return new self(__('That admin can’t reply to contact messages.'));
    }
}
