<?php

declare(strict_types=1);

namespace App\Events\Safety;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Who this member may see changed — a block or an unblock with someone (R-M06-4) —
 * `.connections.changed` on the member's own `App.Models.User.{id}`: the open lists (likes,
 * favourites, interests, inbox, dashboard) and an open profile page re-read through their normal,
 * authorised queries. Sent to BOTH members of the pair, with NO payload: a block stays silent —
 * nothing says who blocked whom, or that it was a block at all.
 */
final class ConnectionsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(public readonly string $userId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'connections.changed';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
