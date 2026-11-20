<?php

declare(strict_types=1);

namespace App\Data\Audit;

/**
 * One line of an audit row's before / after diff (A12 viewer, P9.2a): a dotted key and the two
 * values as display text ('' = absent on that side).
 */
final readonly class AuditDiffLineData
{
    public function __construct(
        public string $key,
        public string $before,
        public string $after,
        public bool $changed,
    ) {}
}
