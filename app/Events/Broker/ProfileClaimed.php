<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A managed client claimed their profile (§11A B.6 claim flow, B.15) — live on the bureau's
 * `broker.{broker}` so open pages drop it (it is out of every ManagedProfileScope now). Payload:
 * the profile code only — no name, no user, no ids.
 */
final class ProfileClaimed implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $profileCode,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'profile.claimed';
    }

    /** @return array{profile: string} */
    public function broadcastWith(): array
    {
        return ['profile' => $this->profileCode];
    }
}
