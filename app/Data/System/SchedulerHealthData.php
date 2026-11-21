<?php

declare(strict_types=1);

namespace App\Data\System;

use Carbon\CarbonImmutable;

/**
 * The Scheduler card of the A12 health panel. `running` = some task started in the last
 * SchedulerHealth::RUNNING_WINDOW_MINUTES (several tasks run every minute, so silence means
 * `schedule:run` / `schedule:work` isn't running). `trackingSince` = the first recorded run.
 */
final readonly class SchedulerHealthData
{
    /** @param  list<ScheduledTaskHealthData>  $tasks */
    public function __construct(
        public array $tasks,
        public bool $running,
        public ?CarbonImmutable $lastRunAt,
        public ?CarbonImmutable $trackingSince,
    ) {}

    public function missedCount(): int
    {
        return count(array_filter($this->tasks, fn (ScheduledTaskHealthData $task): bool => $task->missed));
    }
}
