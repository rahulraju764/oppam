<?php

declare(strict_types=1);

namespace App\Events\Admin;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator opened (claimed) or released an item of an A04 queue (PRD §11.0: "claim-locks
 * broadcast so two moderators see 'being reviewed by Divya'"). Sent on `admin.queues` as
 * `.queue.claim` with the queue key only — the open queue pages re-read themselves and show who
 * holds what through the normal, authorised query. No name or item id travels on the socket.
 */
final class ModerationClaimChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(public readonly string $queueKey) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.queues')];
    }

    public function broadcastAs(): string
    {
        return 'queue.claim';
    }

    /** @return array{queue: string} */
    public function broadcastWith(): array
    {
        return ['queue' => $this->queueKey];
    }
}
