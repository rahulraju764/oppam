<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Services\Sequences\SequenceAllocator;

/**
 * Issues bureau-login codes: "STF" + a sequence number starting at 1001 (decisions 2026-10-26) —
 * the public id of a staff member in URLs and filters (/broker/staff/STF1042), since URLs never
 * carry ULIDs. System-generated only, never reused.
 */
final class StaffCodeGenerator
{
    public const PREFIX = 'STF';

    public const SEQUENCE = 'broker_staff_code';

    public const FIRST_NUMBER = 1001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
