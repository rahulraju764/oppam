<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Contracts\PresenceCounter;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Pusher\Pusher;
use Throwable;

/**
 * Members online = the user count of the `presence.online` channel, asked over the Pusher-protocol
 * HTTP API (Reverb and Pusher both answer it).
 *
 * This runs while the dashboard renders, so it must never hold the page: its own HTTP client gives
 * up after TIMEOUT_SECONDS (the broadcaster's own client waits 30 s), and the answer — a number OR
 * "couldn't ask" — is cached for 30 seconds, so a struggling WebSocket server is asked at most
 * twice a minute however many dashboards are open. Any failure (server down, a log / null driver
 * locally) gives null, never an error on the page — the dashboard then shows a dash.
 */
final class BroadcastPresenceCounter implements PresenceCounter
{
    public const CHANNEL = 'presence-presence.online';

    public const CACHE_KEY = 'admin:online-now';

    public const CACHE_SECONDS = 30;

    public const TIMEOUT_SECONDS = 2.0;

    /** Cached when the server couldn't be asked, so the next render doesn't wait again. */
    private const UNKNOWN = -1;

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    public function online(): ?int
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_int($cached)) {
            return $cached === self::UNKNOWN ? null : $cached;
        }

        $count = $this->ask();
        $this->cache->put(self::CACHE_KEY, $count ?? self::UNKNOWN, self::CACHE_SECONDS);

        return $count;
    }

    private function ask(): ?int
    {
        $connection = (array) $this->config->get('broadcasting.connections.'.$this->config->get('broadcasting.default'), []);

        if (! in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true) || blank($connection['key'] ?? null)) {
            return null;
        }

        try {
            $pusher = new Pusher(
                (string) $connection['key'],
                (string) ($connection['secret'] ?? ''),
                (string) ($connection['app_id'] ?? ''),
                (array) ($connection['options'] ?? []),
                new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => self::TIMEOUT_SECONDS]),
            );

            $info = $pusher->getChannelInfo(self::CHANNEL, ['info' => 'user_count']);

            return max(0, (int) ($info->user_count ?? 0));
        } catch (Throwable) {
            return null;
        }
    }
}
