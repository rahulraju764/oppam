<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduledRunStatus;
use Database\Factories\ScheduledTaskRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A12 system health: one scheduler run of one task (RecordScheduledTaskRun). For a Schedule::job
 * task "finished" means the job was put on its queue — the job's own outcome is in Horizon.
 *
 * @property string $id
 * @property string $task
 * @property string $expression
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property ScheduledRunStatus $status
 * @property int|null $duration_ms
 * @property string|null $error
 */
final class ScheduledTaskRun extends Model
{
    /** @use HasFactory<ScheduledTaskRunFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => ScheduledRunStatus::class,
            'duration_ms' => 'integer',
        ];
    }
}
