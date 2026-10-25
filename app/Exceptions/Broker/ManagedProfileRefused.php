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

    /** R-M13-35: this month's bureau-wide interest allowance is spent. */
    public static function allowanceUsed(int $limit): self
    {
        return new self(__('Your bureau has used all :limit interests for this month. The allowance renews on the 1st.', ['limit' => $limit]), 'BROKER_INTEREST_ALLOWANCE');
    }

    /** R-M13-34: the client must be live on Oppam (approved and not hidden) to send interests. */
    public static function clientNotActive(): self
    {
        return new self(__('This client’s profile must be approved and active before you can send interests for them.'), 'MANAGED_PROFILE_NOT_ACTIVE');
    }

    /** The profile code typed in the Client Inbox doesn't match anyone this client can see. */
    public static function unknownTarget(): self
    {
        return new self(__('No profile with this code is available for this client.'), 'MANAGED_PROFILE_TARGET');
    }

    public static function invalidAssignee(): self
    {
        return new self(__('Choose an active member of your bureau.'), 'MANAGED_PROFILE_ASSIGNEE');
    }
}
