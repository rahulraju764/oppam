<?php

declare(strict_types=1);

namespace App\Exceptions\Moderation;

use RuntimeException;

/** The item can't be decided by this moderator now (claimed by someone else, or already decided). */
final class ModerationItemUnavailable extends RuntimeException
{
    public static function claimedByOther(): self
    {
        return new self(__('Another moderator is reviewing this item.'));
    }

    public static function noLongerWaiting(): self
    {
        return new self(__('This profile is no longer waiting for review (it was changed, suspended or deleted). The item has been closed.'));
    }

    public static function changedSinceViewed(): self
    {
        return new self(__('The member changed this text after you opened it. The list has been refreshed — please review the new text.'));
    }

    public static function alreadyDecided(): self
    {
        return new self(__('This item has already been decided.'));
    }
}
