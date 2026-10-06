<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Enums\NotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * R-M08-2: noisy events (likes, views) make at most one in-app notification per actor, per type,
 * per IST day for a recipient. claim() is atomic (Cache::add) and true only for the first call of
 * the day; the key expires at midnight IST.
 */
final class OncePerDay
{
    public function __construct(private readonly Cache $cache) {}

    public function claim(string $recipientUserId, NotificationEvent $event, string $actorKey): bool
    {
        $now = CarbonImmutable::now((string) config('oppam.display_timezone'));
        $key = 'notify-once:'.$event->value.':'.$recipientUserId.':'.$actorKey.':'.$now->toDateString();

        return $this->cache->add($key, 1, max(60, (int) $now->diffInSeconds($now->endOfDay(), true) + 1));
    }
}
