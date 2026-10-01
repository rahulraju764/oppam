<?php

declare(strict_types=1);

namespace App\Events\Admin;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderation / verification / report queue's waiting count changed (PRD §9.4): admin sidebar
 * badges and queue pages update live. Payload is the queue key and the number only.
 */
final class AdminQueueCountChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $queueKey,
        public readonly int $count,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.queues')];
    }

    public function broadcastAs(): string
    {
        return 'queue.count';
    }

    /** @return array{queue: string, count: int} */
    public function broadcastWith(): array
    {
        return ['queue' => $this->queueKey, 'count' => $this->count];
    }
}
