<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\ScheduledTaskHealthData;
use App\Data\System\SchedulerHealthData;
use App\Domain\System\ScheduleRegistry;
use App\Enums\SettingKey;
use App\Models\ScheduledTaskRun;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

/**
 * A12 "scheduler runs": every task in routes/console.php with its schedule, next due time, latest
 * recorded run (status, duration, error class) and whether a run was MISSED (ScheduleRegistry,
 * grace = A15 `system.missed_schedule_grace_minutes`). Two queries: the latest run per task and
 * the first / latest run overall. Cached 60 s.
 *
 * On a web request the schedule isn't loaded (routes/console.php belongs to the console kernel),
 * so the console kernel is asked to load its commands first — once per cache period.
 */
final class SchedulerHealth
{
    public const CACHE_KEY = 'system:scheduler-health';

    public const CACHE_SECONDS = 60;

    /** Several tasks run every minute: no run at all for this long means the scheduler isn't running. */
    public const RUNNING_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ConsoleKernel $console,
        private readonly Cache $cache,
    ) {}

    public function current(): SchedulerHealthData
    {
        /** @var SchedulerHealthData */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): SchedulerHealthData => $this->measure());
    }

    private function measure(): SchedulerHealthData
    {
        $now = CarbonImmutable::now();
        $grace = Settings::int(SettingKey::SystemMissedScheduleGraceMinutes);

        $span = ScheduledTaskRun::query()->selectRaw('MIN(started_at) AS first_at, MAX(started_at) AS last_at')->toBase()->first();
        $trackingSince = $span?->first_at === null ? null : CarbonImmutable::parse((string) $span->first_at);
        $lastRunAt = $span?->last_at === null ? null : CarbonImmutable::parse((string) $span->last_at);

        $latest = $this->latestRuns();

        $tasks = [];
        foreach ($this->events() as $event) {
            $key = ScheduleRegistry::keyOf($event);
            $timezone = ScheduleRegistry::timezoneOf($event);
            $run = $latest[$key] ?? null;

            $tasks[] = new ScheduledTaskHealthData(
                task: $key,
                expression: $event->expression,
                timezone: $timezone,
                nextDueAt: CarbonImmutable::instance((new CronExpression($event->expression))->getNextRunDate($now->setTimezone($timezone)->toDateTimeImmutable(), 0, false, $timezone))->utc(),
                lastStartedAt: $run?->started_at?->toImmutable(),
                lastStatus: $run?->status,
                lastDurationMs: $run?->duration_ms,
                lastError: $run?->error,
                missed: ScheduleRegistry::isMissed($event->expression, $timezone, $now, $grace, $run?->started_at?->toImmutable(), $trackingSince),
            );
        }

        return new SchedulerHealthData(
            tasks: $tasks,
            running: $lastRunAt !== null && $lastRunAt->gte($now->subMinutes(self::RUNNING_WINDOW_MINUTES)),
            lastRunAt: $lastRunAt,
            trackingSince: $trackingSince,
        );
    }

    /** @return list<\Illuminate\Console\Scheduling\Event> */
    private function events(): array
    {
        if ($this->schedule->events() === []) {
            $this->console->all();   // loads routes/console.php, which defines the schedule
        }

        return array_values($this->schedule->events());
    }

    /** @return array<string, ScheduledTaskRun> task => its latest run */
    private function latestRuns(): array
    {
        $latest = ScheduledTaskRun::query()
            ->select('task', DB::raw('MAX(started_at) AS latest_at'))
            ->groupBy('task');

        $runs = ScheduledTaskRun::query()
            ->joinSub($latest, 'latest', function ($join): void {
                $join->on('scheduled_task_runs.task', '=', 'latest.task')->on('scheduled_task_runs.started_at', '=', 'latest.latest_at');
            })
            ->select('scheduled_task_runs.*')
            ->get();

        $byTask = [];
        foreach ($runs as $run) {
            $byTask[$run->task] ??= $run;   // two runs in the same second: either will do
        }

        return $byTask;
    }
}
