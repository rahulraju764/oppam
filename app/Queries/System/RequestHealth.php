<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\RequestHealthData;
use App\Services\System\Pulse\RequestOutcomes;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Storage as PulseStorage;
use Throwable;

/**
 * A12 "latency / error rate" for the last hour, read back from Pulse (RequestOutcomes recorder):
 * error rate = 5xx / all requests; p95 = the upper edge of the duration bucket holding the 95th
 * percentile. Also Reverb's messages per second (Reverb's own Pulse recorder). "Not recorded"
 * when Pulse is off or its tables can't be read. Cached 60 s.
 */
final class RequestHealth
{
    public const CACHE_KEY = 'system:request-health';

    public const CACHE_SECONDS = 60;

    public function __construct(
        private readonly PulseStorage $pulse,
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    public function current(): RequestHealthData
    {
        /** @var RequestHealthData */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): RequestHealthData => $this->measure());
    }

    /** Average WebSocket messages sent per second over the last hour; null = not recorded. */
    public function reverbMessagesPerSecond(): ?float
    {
        if (! $this->recording('Laravel\\Reverb\\Pulse\\Recorders\\ReverbMessages')) {
            return null;
        }

        /** @var float|null */
        return $this->cache->remember(self::CACHE_KEY.':reverb', self::CACHE_SECONDS, function (): ?float {
            try {
                $total = $this->pulse->aggregateTotal('reverb_message:sent', 'count', CarbonInterval::hour());
            } catch (Throwable) {
                return null;
            }

            return round(($total instanceof Collection ? (float) $total->sum() : $total) / 3600, 2);
        });
    }

    private function measure(): RequestHealthData
    {
        if (! $this->recording(RequestOutcomes::class)) {
            return new RequestHealthData(false, 0, 0, null, false);
        }

        try {
            $statuses = $this->totals(RequestOutcomes::statusTypes(), RequestOutcomes::STATUS_TYPE);
            $durations = $this->totals(RequestOutcomes::durationTypes(), RequestOutcomes::DURATION_TYPE);
        } catch (Throwable) {
            return new RequestHealthData(false, 0, 0, null, false);
        }

        [$p95, $over] = self::percentile95($durations);

        return new RequestHealthData(
            recorded: true,
            requests: array_sum($statuses),
            serverErrors: $statuses['5xx'] ?? 0,
            p95Ms: $p95,
            p95Over: $over,
        );
    }

    /**
     * @param  list<string>  $types
     * @return array<int|string, int> the part of the type after the prefix ("5xx", 250, "over") => count in the last hour
     */
    private function totals(array $types, string $prefix): array
    {
        $totals = $this->pulse->aggregateTotal($types, 'count', CarbonInterval::hour());

        $byKey = [];
        foreach ($totals instanceof Collection ? $totals : [] as $type => $count) {
            $byKey[substr((string) $type, strlen($prefix))] = (int) $count;
        }

        return $byKey;
    }

    /**
     * @param  array<int|string, int>  $buckets  bucket edge (100 … — PHP keys "100" as an int — or "over") => count
     * @return array{0: int|null, 1: bool} the bucket edge holding the 95th percentile; true = above the last edge
     */
    public static function percentile95(array $buckets): array
    {
        $total = array_sum($buckets);
        if ($total === 0) {
            return [null, false];
        }

        $needed = (int) ceil($total * 0.95);
        $seen = 0;
        foreach (RequestOutcomes::BUCKETS as $edge) {
            $seen += $buckets[$edge] ?? 0;
            if ($seen >= $needed) {
                return [$edge, false];
            }
        }

        return [null, true];
    }

    private function recording(string $recorder): bool
    {
        return (bool) $this->config->get('pulse.enabled')
            && (bool) $this->config->get('pulse.recorders.'.$recorder.'.enabled', false);
    }
}
