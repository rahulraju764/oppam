<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Services\Sequences\SequenceAllocator;

/** Issues support ticket codes: "TKT" + a sequence number from 10001. Never reused. */
final class TicketCodeGenerator
{
    public const PREFIX = 'TKT';

    public const SEQUENCE = 'support_ticket_code';

    public const FIRST_NUMBER = 10001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
