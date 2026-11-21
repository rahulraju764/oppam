<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScheduledRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A finished, successful run of a task.
 *
 * @extends Factory<ScheduledTaskRun>
 */
final class ScheduledTaskRunFactory extends Factory
{
    protected $model = ScheduledTaskRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'task' => 'ExpireInterests',
            'expression' => '0 * * * *',
            'started_at' => now(),
            'finished_at' => now(),
            'status' => ScheduledRunStatus::Ok,
            'duration_ms' => 120,
            'error' => null,
        ];
    }

    public function failed(string $error = 'RuntimeException'): self
    {
        return $this->state(['status' => ScheduledRunStatus::Failed, 'error' => $error]);
    }
}
