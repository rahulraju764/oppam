<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member sent an interest to one of the bureau's managed clients (§11A B.15) — live on
 * `broker.{broker}` for the Client Inbox badge and toast. Payload: the client's profile code
 * only — never the sender; pages re-read their own scoped data.
 */
final class ClientInterestReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'client.interest';
    }

    /** @return array{profile: string} */
    public function broadcastWith(): array
    {
        return ['profile' => $this->profileCode];
    }
}
