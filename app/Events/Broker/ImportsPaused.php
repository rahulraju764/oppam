<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The bureau's bulk imports were paused (§11A B.9 auto-pause R-M13-32, or A07 R-A07-9; B.15
 * `ImportsPaused` → banner): live on `broker.{broker}`. Payload: the reason and the batch code that
 * tripped an auto-pause — single-profile creation is not affected.
 */
final class ImportsPaused implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $reason,
        public readonly ?string $batchCode,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'imports.paused';
    }

    /** @return array{reason: string, batch: string|null} */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'batch' => $this->batchCode];
    }
}
