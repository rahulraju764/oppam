<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\System\RealtimeStatsData;

/** A12 "Reverb status": what the WebSocket server reports about itself (connections, channels by type). */
interface RealtimeServerStats
{
    /** Is broadcasting set to a server that can be asked (reverb / pusher with a key)? */
    public function configured(): bool;

    /**
     * Null when not configured or the server didn't answer. `fresh` skips the short cache — for the
     * per-minute sampler; screens use the cached answer.
     */
    public function current(bool $fresh = false): ?RealtimeStatsData;
}
