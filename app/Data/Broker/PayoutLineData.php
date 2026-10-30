<?php

declare(strict_types=1);

namespace App\Data\Broker;

/**
 * One bureau's payout line as PayoutCalculator works it out, in paise (§11A B.10). `payable` false
 * means the balance rolls forward (R-A07-5): nothing is paid and `carriedForwardPaise` holds it.
 */
final readonly class PayoutLineData
{
    public function __construct(
        public int $grossPaise,
        public int $clawbackPaise,
        public int $carriedInPaise,
        public int $tdsPaise,
        public int $netPaise,
        public int $carriedForwardPaise,
        public bool $payable,
        public bool $needsSecondApprover,
    ) {}

    /** A line with nothing in it at all is not written. */
    public function isEmpty(): bool
    {
        return $this->grossPaise === 0 && $this->clawbackPaise === 0 && $this->carriedInPaise === 0;
    }
}
