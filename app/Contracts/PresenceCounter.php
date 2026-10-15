<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * How many members are online right now (PRD A02 "online now from Reverb presence count") — asked
 * of the WebSocket server, which is the only place that knows.
 */
interface PresenceCounter
{
    /** null when the WebSocket server can't be asked (down, not configured). */
    public function online(): ?int;
}
