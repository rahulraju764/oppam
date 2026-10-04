<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * A best-effort live signal (PRD §9.7: the database is the truth, the socket only a hint). The
 * event is dispatched after the surrounding transaction commits; if the broadcast fails (Reverb
 * down, bad credentials) the failure is reported, never thrown — a suspension, password reset or
 * session end must succeed even when the WebSocket server is unreachable. The state it announces
 * is already enforced on the member's next request.
 */
final class LiveSignal
{
    public static function afterCommit(object $event): void
    {
        DB::afterCommit(static function () use ($event): void {
            rescue(static fn () => event($event), report: true);
        });
    }
}
