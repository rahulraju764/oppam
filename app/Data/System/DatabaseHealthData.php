<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * The Database card of the A12 health panel. Every figure is null when the server wouldn't say
 * (a driver without information_schema, or a status variable the app user can't read).
 */
final readonly class DatabaseHealthData
{
    /** @param  list<TableSizeData>  $largestTables */
    public function __construct(
        public string $driver,
        public ?int $sizeBytes,
        public array $largestTables,
        public ?int $connections,
        public ?int $uptimeSeconds,
        public ?int $slowQueries,
    ) {}
}
