<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A07 bureau action doesn't fit the bureau's current state (§11A B.3, B.12 — shown as a toast). */
final class BrokerStateConflict extends RuntimeException
{
    public static function notActive(): self
    {
        return new self(__('Only an active bureau can be suspended.'));
    }

    public static function notSuspended(): self
    {
        return new self(__('Only a suspended bureau can be reactivated.'));
    }

    public static function cannotDeactivate(): self
    {
        return new self(__('Only an active or suspended bureau can be deactivated. Use “Reject application” for one that never started.'));
    }

    public static function alreadyHeld(): self
    {
        return new self(__('Payouts to this bureau are already on hold.'));
    }

    public static function notHeld(): self
    {
        return new self(__('Payouts to this bureau are not on hold.'));
    }

    public static function phoneTaken(): self
    {
        return new self(__('This mobile number already belongs to an Oppam login or another bureau.'));
    }

    public static function kycNotVerified(): self
    {
        return new self(__('Only a verified KYC can be revoked.'));
    }

    public static function creationNotPaused(): self
    {
        return new self(__('Adding and importing profiles is not paused for this bureau.'));
    }

    public static function importsAlreadyPaused(): self
    {
        return new self(__('Imports are already paused for this bureau.'));
    }

    public static function importsNotPaused(): self
    {
        return new self(__('Imports are not paused for this bureau.'));
    }

    public static function notManagedProfile(): self
    {
        return new self(__('That profile is not managed by this bureau (claimed, deleted or another bureau’s).'));
    }

    public static function disputeAlreadyOpen(): self
    {
        return new self(__('This profile already has an open consent dispute.'));
    }

    public static function disputeDecided(): self
    {
        return new self(__('This dispute has already been decided.'));
    }

    public static function ownerProtected(): self
    {
        return new self(__('The owner’s login can’t be changed here. Ownership changes go through support.'));
    }

    public static function staffNotActive(): self
    {
        return new self(__('This staff login is not active.'));
    }

    public static function signalResolved(): self
    {
        return new self(__('This signal has already been resolved.'));
    }
}
