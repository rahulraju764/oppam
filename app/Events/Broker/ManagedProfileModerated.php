<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A04 approved or rejected a bureau-managed profile (§11A B.15) — live on `broker.{broker}` for the
 * bureau's open portal pages (list row status, dashboard feed). Payload: the profile code and the
 * decision only (decisions 2026-10-25) — no reason, no names, no ids; the reason reaches the
 * people who may read it through the ManagedProfileReviewed notification, and pages re-read
 * their own scoped data.
 */
final class ManagedProfileModerated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $profileCode,
        public readonly string $decision,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'profile.moderated';
    }

    /** @return array{profile: string, decision: string} */
    public function broadcastWith(): array
    {
        return ['profile' => $this->profileCode, 'decision' => $this->decision];
    }
}
