<?php

declare(strict_types=1);

namespace App\Jobs\System;

use App\Models\HealthSample;
use App\Models\ScheduledTaskRun;
use App\Models\WebhookReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A12 system health retention, daily: scheduler runs and webhook receipts after 30 days, the
 * per-minute samples after 7 days — in batches, so a long backlog never locks a table for long.
 */
final class PruneSystemHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RUNS_DAYS = 30;

    public const RECEIPTS_DAYS = 30;

    public const SAMPLES_DAYS = 7;

    private const BATCH = 1000;

    public function handle(): void
    {
        $this->prune(ScheduledTaskRun::query()->where('started_at', '<', now()->subDays(self::RUNS_DAYS)));
        $this->prune(WebhookReceipt::query()->where('received_at', '<', now()->subDays(self::RECEIPTS_DAYS)));
        $this->prune(HealthSample::query()->where('sampled_at', '<', now()->subDays(self::SAMPLES_DAYS)));
    }

    /** @param  Builder<covariant Model>  $old */
    private function prune(Builder $old): void
    {
        do {
            $ids = (clone $old)->limit(self::BATCH)->pluck((clone $old)->getModel()->getKeyName());
            $deleted = $ids->isEmpty() ? 0 : (clone $old)->getModel()->newQuery()->whereKey($ids->all())->delete();
        } while ($deleted === self::BATCH);
    }
}
