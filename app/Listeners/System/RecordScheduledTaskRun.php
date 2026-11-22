<?php

declare(strict_types=1);

namespace App\Listeners\System;

use App\Domain\System\ScheduleRegistry;
use App\Enums\ScheduledRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;

/**
 * A12 system health: every scheduler run of a task becomes a scheduled_task_runs row — RUNNING
 * when it starts, then OK / FAILED with its duration; a task whose filters said "not now" is
 * SKIPPED. A command that exits non-zero is FAILED (see handleFinished). Capture must never stop a
 * task: every write is rescued (a failure is only reported).
 * The started row is found again through the Event object, which schedule:run keeps for the run.
 */
final class RecordScheduledTaskRun
{
    /** @var array<int, string> spl_object_id(task) => scheduled_task_runs.id, for this process */
    private static array $running = [];

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        rescue(function () use ($event): void {
            $run = new ScheduledTaskRun;
            $run->forceFill([
                'task' => ScheduleRegistry::keyOf($event->task),
                'expression' => mb_substr($event->task->expression, 0, 60),
                'started_at' => now(),
                'status' => ScheduledRunStatus::Running,
            ])->save();

            self::$running[spl_object_id($event->task)] = $run->id;
        });
    }

    /**
     * schedule:run fires Finished BEFORE it throws for a non-zero exit code (and only then fires
     * Failed), so the exit code is read here: non-zero → FAILED "Exit code N". The Failed event that
     * follows finds the row already finished and leaves it. A background task's exit code isn't
     * known yet when Finished fires — OK there means "launched".
     */
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $durationMs = (int) round($event->runtime * 1000);
        $exitCode = $task->exitCode;

        if ($exitCode !== null && (int) $exitCode !== 0 && ! $task->runInBackground) {
            $this->finish($task, ScheduledRunStatus::Failed, $durationMs, 'Exit code '.(int) $exitCode);

            return;
        }

        $this->finish($task, ScheduledRunStatus::Ok, $durationMs, null);
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->finish($event->task, ScheduledRunStatus::Failed, null, mb_substr($event->exception::class, 0, 120));
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        rescue(function () use ($event): void {
            (new ScheduledTaskRun)->forceFill([
                'task' => ScheduleRegistry::keyOf($event->task),
                'expression' => mb_substr($event->task->expression, 0, 60),
                'started_at' => now(),
                'finished_at' => now(),
                'status' => ScheduledRunStatus::Skipped,
            ])->save();
        });
    }

    private function finish(Event $task, ScheduledRunStatus $status, ?int $durationMs, ?string $error): void
    {
        rescue(function () use ($task, $status, $durationMs, $error): void {
            $id = self::$running[spl_object_id($task)] ?? null;
            unset(self::$running[spl_object_id($task)]);

            $run = $id === null ? null : ScheduledTaskRun::query()->find($id);
            if ($run === null) {
                return;   // its start wasn't recorded (rescued): nothing to finish
            }

            $run->forceFill([
                'finished_at' => now(),
                'status' => $status,
                'duration_ms' => $durationMs ?? (int) min(4_294_967_295, $run->started_at->diffInMilliseconds(now())),
                'error' => $error,
            ])->save();
        });
    }
}
