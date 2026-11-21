<?php

declare(strict_types=1);

namespace App\Enums;

/** A12 system health: how one scheduler run of a task ended (scheduled_task_runs.status). */
enum ScheduledRunStatus: string
{
    case Running = 'RUNNING';
    case Ok = 'OK';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::Running => __('Running'),
            self::Ok => __('OK'),
            self::Failed => __('Failed'),
            self::Skipped => __('Skipped'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Running => 'default',
            self::Ok => 'success',
            self::Failed => 'danger',
            self::Skipped => 'muted',
        };
    }
}
