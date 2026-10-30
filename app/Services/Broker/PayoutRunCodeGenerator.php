<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Services\Sequences\SequenceAllocator;

/**
 * Issues payout-run codes: "PAY" + a sequence number from 1001 — the public id of a run in A07
 * URLs and on statements (URLs never carry ULIDs). System-generated only, never reused.
 */
final class PayoutRunCodeGenerator
{
    public const PREFIX = 'PAY';

    public const SEQUENCE = 'broker_payout_run_code';

    public const FIRST_NUMBER = 1001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
