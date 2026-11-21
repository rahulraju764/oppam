<?php

declare(strict_types=1);

namespace App\Domain\System;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;

/**
 * A12 system health — pure rules about scheduled tasks:
 *  - keyOf(): a short, stable name for a task (job class basename, artisan command, or the name
 *    given to a closure task) — the same on every server and every run;
 *  - lastDueAt(): the most recent time the task's cron expression was due at or before a moment,
 *    in the task's own timezone (many tasks run on IST);
 *  - isMissed(): a due time passed (by the grace minutes) with no run started since it, counted
 *    only for due times after capture began (no false alarms right after deploy).
 */
final class ScheduleRegistry
{
    public static function keyOf(Event $event): string
    {
        $description = trim((string) $event->description);

        if ($description !== '') {
            $key = str_contains($description, '\\') ? class_basename($description) : $description;
        } else {
            // "'/usr/bin/php' 'artisan' metrics:snapshot --yesterday" → "metrics:snapshot --yesterday"
            $command = str_replace(["'", '"'], '', (string) $event->command);
            $key = trim((string) preg_replace('/^.*?\bartisan\s+/', '', $command));
        }

        return mb_substr($key !== '' ? $key : 'task', 0, 120);
    }

    public static function timezoneOf(Event $event): string
    {
        $zone = $event->timezone;   // null at run time when neither the task nor the schedule sets one
        $name = $zone instanceof DateTimeZone ? $zone->getName() : (string) $zone;

        return $name !== '' ? $name : (string) config('app.timezone', 'UTC');
    }

    public static function lastDueAt(string $expression, string $timezone, CarbonImmutable $at): CarbonImmutable
    {
        $previous = (new CronExpression($expression))->getPreviousRunDate($at->setTimezone($timezone)->toDateTimeImmutable(), 0, true, $timezone);

        return CarbonImmutable::instance($previous)->utc();
    }

    /**
     * @param  CarbonImmutable  $now  current time
     * @param  CarbonImmutable|null  $lastStartedAt  the task's latest recorded run
     * @param  CarbonImmutable|null  $trackingSince  the first run recorded for any task (null = nothing recorded yet)
     */
    public static function isMissed(string $expression, string $timezone, CarbonImmutable $now, int $graceMinutes, ?CarbonImmutable $lastStartedAt, ?CarbonImmutable $trackingSince): bool
    {
        if ($trackingSince === null) {
            return false;
        }

        // The latest due time that is at least `grace` minutes old: its run should have started by now.
        $due = self::lastDueAt($expression, $timezone, $now->subMinutes(max(1, $graceMinutes)));

        if ($due->lt($trackingSince)) {
            return false;
        }

        return $lastStartedAt === null || $lastStartedAt->lt($due);
    }
}
