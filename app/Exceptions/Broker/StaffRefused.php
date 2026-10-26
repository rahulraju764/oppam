<?php

declare(strict_types=1);

namespace App\Exceptions\Broker;

use RuntimeException;

/**
 * A staff-account step the bureau's state doesn't allow (§11A B.8). The message is safe to show;
 * `reason` is machine-readable.
 */
final class StaffRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEnabled(): self
    {
        return new self(__('Staff accounts are coming soon.'), 'BROKER_STAFF_DISABLED');
    }

    public static function bureauNotVerified(): self
    {
        return new self(__('Complete your bureau’s KYC before inviting staff.'), 'BROKER_KYC_REQUIRED');
    }

    /** R-M13-25. */
    public static function noSeat(int $limit): self
    {
        return new self(__('All :limit staff seats are in use. Deactivate a staff member or cancel an invitation to free a seat.', ['limit' => $limit]), 'BROKER_STAFF_SEATS');
    }

    /** R-M13-22 — never says whose account the number belongs to. */
    public static function numberNotAllowed(): self
    {
        return new self(__('This number can’t be invited. Ask for another mobile number.'), 'BROKER_STAFF_NUMBER');
    }

    /** R-M13-24: the Owner's own login is not managed from the staff pages. */
    public static function ownerProtected(): self
    {
        return new self(__('The bureau owner’s own login can’t be changed here.'), 'BROKER_STAFF_OWNER');
    }

    public static function alreadyDeactivated(): self
    {
        return new self(__('This staff member is already deactivated.'), 'BROKER_STAFF_STATUS');
    }

    public static function invitationInvalid(): self
    {
        return new self(__('This invite link is no longer valid. Ask the bureau owner to send a new one.'), 'BROKER_STAFF_INVITE');
    }
}
