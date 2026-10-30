<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Services\Sequences\SequenceAllocator;

/**
 * Issues consent-dispute codes: "DSP" + a sequence number from 1001 — the public id of a dispute in
 * A07 and the audit log (URLs never carry ULIDs). System-generated only, never reused.
 */
final class DisputeCodeGenerator
{
    public const PREFIX = 'DSP';

    public const SEQUENCE = 'broker_consent_dispute_code';

    public const FIRST_NUMBER = 1001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
