<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * The Queues card of the A12 health panel. `horizon` = Horizon's master status (running / paused /
 * inactive) on a Redis connection, null where Horizon isn't used (local database driver).
 */
final readonly class QueueHealthData
{
    /** @param  list<QueueRowData>  $queues */
    public function __construct(
        public string $connection,
        public ?string $horizon,
        public array $queues,
        public int $failedLastHour,
        public int $failed24h,
    ) {}
}
