<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Services\Sequences\SequenceAllocator;

/** Issues A10 abuse case codes: "CASE" + a sequence number from 10001. Never reused. */
final class CaseCodeGenerator
{
    public const PREFIX = 'CASE';

    public const SEQUENCE = 'abuse_case_code';

    public const FIRST_NUMBER = 10001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
