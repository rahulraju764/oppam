<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Services\Sequences\SequenceAllocator;

/**
 * Issues public profile codes: "OPM" + a sequence number starting at 10001 (PRD §7.2
 * "OPM12370"). Codes come only from here — never from user input — and are never reused,
 * even after a profile is deleted.
 */
final class ProfileCodeGenerator
{
    public const PREFIX = 'OPM';

    public const SEQUENCE = 'profile_code';

    public const FIRST_NUMBER = 10001;

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function next(): string
    {
        return self::PREFIX.$this->sequences->next(self::SEQUENCE, self::FIRST_NUMBER);
    }
}
