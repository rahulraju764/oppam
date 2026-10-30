<?php

declare(strict_types=1);

namespace App\Data\Broker;

/** A07 Overview edits of one bureau (§11A B.12, R-A07-11): commission rate and the bureau's limits. */
final readonly class BureauTermsData
{
    public function __construct(
        public int $commissionBps,
        public int $interestAllowanceMonthly,
        public int $staffSeatLimit,
        public int $importDailyCap,
    ) {}
}
