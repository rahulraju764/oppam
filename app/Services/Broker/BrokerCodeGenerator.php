<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Services\Sequences\SequenceAllocator;

/**
 * Issues bureau codes: "BRK" + a sequence number starting at 1001 (PRD §11A "BRK1042"). Codes come
 * only from here — never from input (R-A07-1) — and are never reused.
 */
final class BrokerCodeGenerator
{
    public const PREFIX = 'BRK';

    public const SEQUENCE = 'broker_code';

    public const FIRST_NUMBER = 1001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
