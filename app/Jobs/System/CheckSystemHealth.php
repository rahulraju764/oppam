<?php

declare(strict_types=1);

namespace App\Jobs\System;

use App\Actions\System\SyncSystemAlerts;
use App\Data\System\AlertThresholdsData;
use App\Data\System\HealthReadingsData;
use App\Domain\System\AlertRules;
use App\Queries\System\DeliveryHealth;
use App\Queries\System\QueueHealth;
use App\Queries\System\RealtimeHealth;
use App\Queries\System\SchedulerHealth;
use App\Queries\System\WebhookHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A12 alerts (P9.2b2), every minute: read the health readers, run AlertRules against the A15
 * thresholds, and SyncSystemAlerts (open → one email; resolve once clear). The readers' own caches
 * are reused where a stale minute doesn't matter (scheduler, delivery); failed jobs, signature failures,
 * lag and the Reverb trend are read fresh. Every source is
 * rescued on its own: one that can't be read is "unknown" — its alert is neither opened nor closed.
 */
final class CheckSystemHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // the next minute's run is the retry

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(
        QueueHealth $queues,
        SchedulerHealth $scheduler,
        WebhookHealth $webhooks,
        DeliveryHealth $delivery,
        RealtimeHealth $realtime,
        SyncSystemAlerts $sync,
    ): void {
        $readings = new HealthReadingsData(
            failedJobsLastHour: rescue(fn (): int => $queues->failedSince(now()->toImmutable()->subHour()), null),
            missedSchedules: rescue(fn (): int => $scheduler->current()->missedCount(), null),
            signatureFailuresLastHour: rescue(fn (): int => $webhooks->signatureRejectionsSince(now()->toImmutable()->subHour()), null),
            delivery: rescue(fn (): array => $delivery->current(), null),
            broadcastLag: rescue(fn () => $realtime->broadcastLag(), null),
            reverb: rescue(fn () => $realtime->trend(), null),
        );

        $thresholds = AlertThresholdsData::fromSettings();

        $sync->handle(AlertRules::evaluate($readings, $thresholds), $thresholds->clearMinutes);
    }
}
