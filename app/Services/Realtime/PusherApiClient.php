<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository as Config;
use Pusher\Pusher;

/**
 * A Pusher-protocol HTTP API client (Reverb and Pusher both answer it) for reading the WebSocket
 * server's state while an admin page renders — with its own short timeout, because the
 * broadcaster's client waits 30 s. Null when broadcasting isn't reverb / pusher or has no key
 * (log / null drivers locally and in tests).
 */
final class PusherApiClient
{
    public const TIMEOUT_SECONDS = 2.0;

    public function __construct(private readonly Config $config) {}

    public function make(float $timeout = self::TIMEOUT_SECONDS): ?Pusher
    {
        $connection = (array) $this->config->get('broadcasting.connections.'.$this->config->get('broadcasting.default'), []);

        if (! in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true) || blank($connection['key'] ?? null)) {
            return null;
        }

        return new Pusher(
            (string) $connection['key'],
            (string) ($connection['secret'] ?? ''),
            (string) ($connection['app_id'] ?? ''),
            (array) ($connection['options'] ?? []),
            new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]),
        );
    }
}
