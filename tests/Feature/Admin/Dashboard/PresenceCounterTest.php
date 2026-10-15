<?php

declare(strict_types=1);

use App\Contracts\PresenceCounter;
use App\Services\Realtime\BroadcastPresenceCounter;
use Illuminate\Support\Facades\Cache;

/*
| P3.5 — "online now" (PRD A02) is asked of the WebSocket server while the dashboard renders, so it
| must never hold the page: no answer without a Pusher-protocol driver, a short timeout of its own,
| and the result — a number OR "couldn't ask" — cached so a struggling server is not asked again.
*/

beforeEach(fn () => Cache::flush());

it('is the bound PresenceCounter and gives up quickly (well under the broadcaster\'s own 30 s)', function (): void {
    expect(app(PresenceCounter::class))->toBeInstanceOf(BroadcastPresenceCounter::class)
        ->and(BroadcastPresenceCounter::TIMEOUT_SECONDS)->toBeLessThanOrEqual(2.0)
        ->and(BroadcastPresenceCounter::CACHE_SECONDS)->toBe(30)
        ->and(BroadcastPresenceCounter::CHANNEL)->toBe('presence-presence.online');   // what resources/js/realtime.js joins as presence.online
});

it('without a Reverb / Pusher connection there is no number — and no HTTP call', function (string $driver): void {
    config(['broadcasting.default' => $driver]);

    expect(app(PresenceCounter::class)->online())->toBeNull();
})->with(['log', 'null']);

it('an unreachable server gives null fast, and the failure is cached: the next render does not wait again', function (): void {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
        'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],   // nothing listens on port 9
    ]]);
    $counter = app(PresenceCounter::class);

    $started = microtime(true);
    expect($counter->online())->toBeNull();
    $first = microtime(true) - $started;

    $started = microtime(true);
    expect($counter->online())->toBeNull();
    $second = microtime(true) - $started;

    expect($first)->toBeLessThan(BroadcastPresenceCounter::TIMEOUT_SECONDS + 2.0)
        ->and($second)->toBeLessThan(0.2)
        ->and(Cache::get(BroadcastPresenceCounter::CACHE_KEY))->toBe(-1);
});

it('a cached number is returned without asking again', function (): void {
    config(['broadcasting.default' => 'log']);   // asking would give null
    Cache::put(BroadcastPresenceCounter::CACHE_KEY, 17, 30);

    expect(app(PresenceCounter::class)->online())->toBe(17);
});
