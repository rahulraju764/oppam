<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Contracts\RealtimeServerStats;
use App\Data\System\RealtimeStatsData;
use App\Domain\System\ChannelTypes;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * A12 "Reverb status" over the Pusher-protocol HTTP API: GET /connections and GET /channels
 * (subscription_count / user_count). Like BroadcastPresenceCounter it must never hold a page: a
 * 2-second client and a 30-second cache of the answer, "didn't answer" included. Channel names
 * are reduced to their type here and never returned.
 */
final class ReverbServerStats implements RealtimeServerStats
{
    public const CACHE_KEY = 'system:realtime-stats';

    public const CACHE_SECONDS = 30;

    private const UNKNOWN = 'unknown';

    public function __construct(
        private readonly PusherApiClient $client,
        private readonly Cache $cache,
    ) {}

    public function configured(): bool
    {
        return $this->client->make() !== null;
    }

    public function current(bool $fresh = false): ?RealtimeStatsData
    {
        if (! $fresh) {
            $cached = $this->cache->get(self::CACHE_KEY);
            if ($cached instanceof RealtimeStatsData) {
                return $cached;
            }
            if ($cached === self::UNKNOWN) {
                return null;
            }
        }

        $stats = $this->ask();
        $this->cache->put(self::CACHE_KEY, $stats ?? self::UNKNOWN, self::CACHE_SECONDS);

        return $stats;
    }

    private function ask(): ?RealtimeStatsData
    {
        try {
            $pusher = $this->client->make();
            if ($pusher === null) {
                return null;
            }

            $connections = $pusher->get('/connections', [], true);
            $channels = $pusher->get('/channels', ['info' => 'subscription_count,user_count'], true);
        } catch (Throwable) {
            return null;
        }

        return self::fromApi((array) $connections, (array) $channels);
    }

    /**
     * @param  array<mixed>  $connections  GET /connections: {connections: N}
     * @param  array<mixed>  $channels  GET /channels: {channels: {name: {subscription_count | user_count}}}
     */
    public static function fromApi(array $connections, array $channels): RealtimeStatsData
    {
        $byType = [];
        $subscriptions = [];
        foreach ((array) ($channels['channels'] ?? []) as $name => $info) {
            $type = ChannelTypes::of((string) $name);
            $info = (array) $info;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $subscriptions[$type] = ($subscriptions[$type] ?? 0) + (int) ($info['subscription_count'] ?? $info['user_count'] ?? 0);
        }

        return new RealtimeStatsData(
            connections: max(0, (int) ($connections['connections'] ?? 0)),
            channels: $byType,
            subscriptions: $subscriptions,
        );
    }
}
