<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Data\Broker\PayoutLineData;

/**
 * Payout maths for one bureau (§11A B.10, R-A07-4, R-A07-5, B.19; decisions 2026-10-29) — pure,
 * integer paise, no I/O:
 *
 *   balance = gross (EARNED commissions) − clawback (commissions voided after they were paid)
 *             + carried in (an earlier line's balance, may be negative)
 *   TDS     = balance × tds bps, half-up (only on a positive balance; 0 bps until the owner sets it)
 *   net     = balance − TDS
 *
 * A net under the minimum (or nothing / less than nothing) is not paid: the whole balance, before
 * TDS, rolls forward to the next run (TDS is deducted when it is finally paid). A paid line above
 * the second-approver threshold needs a second approver.
 */
final class PayoutCalculator
{
    public function __construct(
        private readonly int $minimumPaise,
        private readonly int $secondApproverPaise,
        private readonly int $tdsBps,
    ) {}

    public function line(int $grossPaise, int $clawbackPaise, int $carriedInPaise): PayoutLineData
    {
        $balance = $grossPaise - $clawbackPaise + $carriedInPaise;
        $tds = $balance > 0 ? intdiv($balance * $this->tdsBps + 5_000, 10_000) : 0;
        $net = $balance - $tds;

        if ($balance <= 0 || $net < $this->minimumPaise) {
            return new PayoutLineData($grossPaise, $clawbackPaise, $carriedInPaise, 0, 0, $balance, false, false);
        }

        return new PayoutLineData($grossPaise, $clawbackPaise, $carriedInPaise, $tds, $net, 0, true, $net > $this->secondApproverPaise);
    }
}
