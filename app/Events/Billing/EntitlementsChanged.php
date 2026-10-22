<?php

declare(strict_types=1);

namespace App\Events\Billing;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The member's plan changed (F04: "EntitlementsChanged (live: composer unlocks, quotas refresh,
 * Premium badge)") — `.entitlements.changed` on their private channel. A hint only: the open pages
 * re-read themselves and every limit is enforced by the server. Plan code, name and the end date
 * (IST, display text) — no ids, no amounts.
 */
final class EntitlementsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $userId,
        public readonly string $planCode,
        public readonly string $planName,
        public readonly string $until,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'entitlements.changed';
    }

    /** @return array{plan: string, plan_name: string, until: string} */
    public function broadcastWith(): array
    {
        return ['plan' => $this->planCode, 'plan_name' => $this->planName, 'until' => $this->until];
    }
}
