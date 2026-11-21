<?php

declare(strict_types=1);

namespace App\Jobs\System;

use App\Contracts\RealtimeServerStats;
use App\Enums\HealthMetric;
use App\Models\HealthSample;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A12 system health, every minute, run INSIDE the scheduler (not queued — a stuck queue must not
 * stop the measuring):
 *  - samples Reverb's open connections (null when the server didn't answer) into health_samples;
 *  - sends a canary job onto the `broadcasts` queue carrying the time it was sent, unless one is
 *    still waiting (CANARY_KEY holds its send time, so a stuck queue shows a growing lag).
 */
final class SampleSystemHealth
{
    use Dispatchable;

    /** The send time (ms) of the canary still waiting on the broadcasts queue. */
    public const CANARY_KEY = 'system:broadcast-canary';

    /** A canary lost for this long is given up and a new one sent. */
    public const CANARY_TTL_SECONDS = 900;

    public function handle(RealtimeServerStats $stats, Cache $cache): void
    {
        if ($stats->configured()) {
            HealthSample::record(HealthMetric::ReverbConnections, $stats->current(fresh: true)?->connections);
        }

        $sentAt = (int) floor(microtime(true) * 1000);
        if ($cache->add(self::CANARY_KEY, $sentAt, self::CANARY_TTL_SECONDS)) {
            MeasureBroadcastLag::dispatch($sentAt);
        }
    }
}
