<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * The Requests card of the A12 health panel (last hour, from the Pulse RequestOutcomes recorder).
 * `recorded` false = Pulse is off / unreadable. `p95Ms` is the upper edge of the duration bucket
 * that holds the 95th percentile (shown as "≤ X ms"); null with `p95Over` true = above the last
 * bucket edge. Counts are Pulse's sampled counts.
 */
final readonly class RequestHealthData
{
    public function __construct(
        public bool $recorded,
        public int $requests,
        public int $serverErrors,
        public ?int $p95Ms,
        public bool $p95Over,
    ) {}

    public function errorRatePct(): ?float
    {
        return $this->requests === 0 ? null : round($this->serverErrors * 100 / $this->requests, 2);
    }
}
