<?php

declare(strict_types=1);

namespace App\Data\System;

use App\Enums\ScheduledRunStatus;
use Carbon\CarbonImmutable;

/** One scheduled task on the A12 health panel: when it runs, its latest run, and whether a run was missed. */
final readonly class ScheduledTaskHealthData
{
    public function __construct(
        public string $task,
        public string $expression,
        public string $timezone,
        public CarbonImmutable $nextDueAt,
        public ?CarbonImmutable $lastStartedAt,
        public ?ScheduledRunStatus $lastStatus,
        public ?int $lastDurationMs,
        public ?string $lastError,
        public bool $missed,
    ) {}
}
