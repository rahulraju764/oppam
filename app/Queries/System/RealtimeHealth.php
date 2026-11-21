<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Contracts\RealtimeServerStats;
use App\Data\System\BroadcastLagData;
use App\Data\System\RealtimeHealthData;
use App\Enums\HealthMetric;
use App\Jobs\System\SampleSystemHealth;
use App\Models\HealthSample;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A12 "Reverb status" and "broadcast queue lag":
 *  - Reverb: what the server says now (RealtimeServerStats, cached 30 s), messages per second
 *    (Reverb's Pulse recorder, via RequestHealth) and the latest DETECTED restart — a minute the
 *    server answered right after a minute it didn't, or one where connections fell to a tenth of
 *    the minute before (clients reconnect within seconds, so a restart is often only a dip);
 *  - lag: the latest canary measurement (MeasureBroadcastLag) or, if a canary is still waiting,
 *    its age — whichever is larger.
 */
final class RealtimeHealth
{
    public const CACHE_KEY = 'system:realtime-restart';

    public const CACHE_SECONDS = 60;

    /** A measurement older than this is not "current". */
    public const LAG_FRESH_MINUTES = 10;

    /** A dip from at least this many connections to a tenth of them counts as a restart. */
    public const RESTART_DIP_FROM = 20;

    public function __construct(
        private readonly RealtimeServerStats $stats,
        private readonly RequestHealth $requests,
        private readonly Cache $cache,
    ) {}

    public function reverb(): RealtimeHealthData
    {
        $configured = $this->stats->configured();

        return new RealtimeHealthData(
            configured: $configured,
            stats: $configured ? $this->stats->current() : null,
            messagesPerSecond: $configured ? $this->requests->reverbMessagesPerSecond() : null,
            lastRestartAt: $configured ? $this->lastRestart() : null,
        );
    }

    public function broadcastLag(): BroadcastLagData
    {
        $sample = HealthSample::query()
            ->where('metric', HealthMetric::BroadcastLagMs->value)
            ->where('sampled_at', '>=', now()->subMinutes(self::LAG_FRESH_MINUTES))
            ->whereNotNull('value')
            ->orderByDesc('sampled_at')->orderByDesc('id')
            ->first();

        $pendingSince = $this->cache->get(SampleSystemHealth::CANARY_KEY);
        $pendingMs = is_int($pendingSince) ? max(0, (int) floor(microtime(true) * 1000) - $pendingSince) : null;

        $measured = $sample?->value;
        $lag = $measured === null && $pendingMs === null ? null : max($measured ?? 0, $pendingMs ?? 0);

        return new BroadcastLagData(
            lagMs: $lag,
            measuredAt: $sample?->sampled_at?->toImmutable(),
            pending: $pendingMs !== null,
        );
    }

    private function lastRestart(): ?CarbonImmutable
    {
        $at = $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): ?string {
            $samples = HealthSample::query()
                ->where('metric', HealthMetric::ReverbConnections->value)
                ->where('sampled_at', '>=', now()->subDay())
                ->orderBy('sampled_at')->orderBy('id')
                ->get(['id', 'value', 'sampled_at']);

            $restart = null;
            $previous = false;   // false = no sample yet
            foreach ($samples as $sample) {
                if ($previous !== false && $sample->value !== null && self::isRestart($previous, $sample->value)) {
                    $restart = $sample->sampled_at->toIso8601String();
                }
                $previous = $sample->value;
            }

            return $restart;
        });

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    /** @param  int|null  $before  the previous minute's connections (null = server didn't answer) */
    public static function isRestart(?int $before, int $now): bool
    {
        return $before === null || ($before >= self::RESTART_DIP_FROM && $now * 10 <= $before);
    }
}
