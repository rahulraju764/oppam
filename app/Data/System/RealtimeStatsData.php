<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * What the WebSocket server reports right now (A12 "Reverb status"): open connections and, per
 * channel type (chat, inbox, online presence, member, bureau, admin, announcements, other), how
 * many channels are occupied and how many subscriptions they hold. Channel NAMES never leave the
 * reader — they carry conversation / profile / broker ids.
 */
final readonly class RealtimeStatsData
{
    /**
     * @param  array<string, int>  $channels  type => occupied channels
     * @param  array<string, int>  $subscriptions  type => subscriptions (presence channels: users)
     */
    public function __construct(
        public int $connections,
        public array $channels,
        public array $subscriptions,
    ) {}
}
