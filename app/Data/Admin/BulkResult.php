<?php

declare(strict_types=1);

namespace App\Data\Admin;

/** Outcome of an A03 bulk action: members changed, and members skipped (wrong state, no email…). */
final readonly class BulkResult
{
    public function __construct(
        public int $done,
        public int $skipped,
    ) {}
}
