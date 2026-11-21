<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * One application queue on the A12 health panel. Null = the connection can't say (`waitSeconds`
 * and `retrying` are known for the database driver and, through Horizon, for Redis).
 */
final readonly class QueueRowData
{
    public function __construct(
        public string $name,
        public ?int $waiting,
        public int $failed24h,
        public ?int $retrying,
        public ?int $waitSeconds,
    ) {}
}
