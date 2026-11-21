<?php

declare(strict_types=1);

namespace App\Jobs\System;

use App\Enums\HealthMetric;
use App\Models\HealthSample;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A12 "broadcast queue lag": a canary on the `broadcasts` queue (where every ShouldBroadcast event
 * waits). When a worker picks it up, the time since it was sent is the lag a live update would
 * have had; it is kept in health_samples and the "canary waiting" mark is cleared.
 */
final class MeasureBroadcastLag implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $sentAtMs)
    {
        $this->onQueue('broadcasts');
    }

    public function handle(Cache $cache): void
    {
        HealthSample::record(HealthMetric::BroadcastLagMs, max(0, (int) floor(microtime(true) * 1000) - $this->sentAtMs));

        if ($cache->get(SampleSystemHealth::CANARY_KEY) === $this->sentAtMs) {
            $cache->forget(SampleSystemHealth::CANARY_KEY);
        }
    }
}
