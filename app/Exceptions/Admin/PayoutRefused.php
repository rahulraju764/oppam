<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A07 payout step doesn't fit the run's current state (§11A B.10, B.19 — shown to the admin as a toast). */
final class PayoutRefused extends RuntimeException
{
    /** True when the refusal is lifted by giving an override reason (a paid run overlaps — B.17). */
    public bool $overridable = false;

    public static function runStillOpen(string $code): self
    {
        return new self(__('Run :code is not paid yet. Finish or discard it before starting another run.', ['code' => $code]));
    }

    public static function paidPeriodNeedsOverride(string $code): self
    {
        $refused = new self(__('Run :code already paid part of this period. Give an override reason to run it again.', ['code' => $code]));
        $refused->overridable = true;

        return $refused;
    }

    public static function notDraft(): self
    {
        return new self(__('Only a draft run can be changed. This one has already been approved.'));
    }

    public static function alreadyApproved(): self
    {
        return new self(__('This run has already been approved.'));
    }

    public static function secondApproverMustDiffer(): self
    {
        return new self(__('A payout line is above the second-approver limit: a different admin must give the second approval.'));
    }

    public static function notApproved(): self
    {
        return new self(__('A run is paid only after it has been approved.'));
    }

    public static function lineNotPayable(): self
    {
        return new self(__('This line is not waiting for payment (already paid, or rolled forward).'));
    }

    public static function bureauNotPayable(string $code): self
    {
        return new self(__('Bureau :code can’t be paid now: it must be active and KYC-verified, with payouts not on hold.', ['code' => $code]));
    }

    public static function utrUsed(): self
    {
        return new self(__('This UTR is already recorded against another payout.'));
    }

    public static function busy(): self
    {
        return new self(__('Another admin is creating a payout run right now. Try again in a moment.'));
    }
}
