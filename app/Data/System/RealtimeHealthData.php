<?php

declare(strict_types=1);

namespace App\Data\System;

use Carbon\CarbonImmutable;

/**
 * The Reverb card of the A12 health panel. `configured` false = broadcasting is not reverb /
 * pusher here (nothing to ask); `stats` null while configured = the server didn't answer.
 * `messagesPerSecond` comes from Reverb's Pulse recorder (null = not recorded). `lastRestartAt`
 * is DETECTED: the latest minute the server answered after a minute it didn't.
 */
final readonly class RealtimeHealthData
{
    public function __construct(
        public bool $configured,
        public ?RealtimeStatsData $stats,
        public ?float $messagesPerSecond,
        public ?CarbonImmutable $lastRestartAt,
    ) {}
}
