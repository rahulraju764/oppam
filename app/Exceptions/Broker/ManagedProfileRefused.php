<?php

declare(strict_types=1);

namespace App\Exceptions\Broker;

use RuntimeException;

/**
 * A bureau action on a managed profile that its current state doesn't allow (§11A B.6). The
 * message is safe to show to the bureau; `code` is machine-readable.
 */
final class ManagedProfileRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function wrongStatus(): self
    {
        return new self(__('This can’t be done while the profile has its current status.'), 'MANAGED_PROFILE_STATUS');
    }

    public static function restoreWindowOver(): self
    {
        return new self(__('This profile was deleted more than :days days ago and can no longer be restored.', ['days' => (int) config('oppam.broker.managed_restore_days')]), 'MANAGED_PROFILE_RESTORE_WINDOW');
    }

    public static function invalidAssignee(): self
    {
        return new self(__('Choose an active member of your bureau.'), 'MANAGED_PROFILE_ASSIGNEE');
    }
}
