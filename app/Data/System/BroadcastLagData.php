<?php

declare(strict_types=1);

namespace App\Data\System;

use Carbon\CarbonImmutable;

/**
 * A12 "broadcast queue lag": how long the latest canary job waited on the `broadcasts` queue.
 * `lagMs` is the larger of the last measured wait and the age of a canary still waiting
 * (`pending`), so a stuck queue shows a growing lag rather than the last good number.
 * Null = nothing measured in the last 10 minutes (scheduler or worker not running).
 */
final readonly class BroadcastLagData
{
    public function __construct(
        public ?int $lagMs,
        public ?CarbonImmutable $measuredAt,
        public bool $pending,
    ) {}
}
