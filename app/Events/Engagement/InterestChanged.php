<?php

declare(strict_types=1);

namespace App\Events\Engagement;

use App\Domain\Safety\BlockList;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An interest between this member and another changed (sent, accepted, declined, withdrawn) —
 * `.interest.changed` on the member's private channel (PRD M06 acceptance: "accepting an interest
 * makes the Chat button active on both sides live"). The notification (M08) tells the member in
 * words; this tells the open page: the Interest / Chat buttons for that profile switch to `state`
 * as THIS member sees it (none | sent | received | connected) and /interests re-reads itself.
 * Codes and a state only; it is a hint — every list and rule is re-read through the server.
 * Never delivered inside a blocked pair.
 */
final class InterestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $toUserId,
        public readonly string $toProfileId,
        public readonly string $otherProfileId,
        public readonly string $otherCode,
        public readonly string $state,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->toUserId)];
    }

    public function broadcastAs(): string
    {
        return 'interest.changed';
    }

    /** @return array{code: string, state: string} */
    public function broadcastWith(): array
    {
        return ['code' => $this->otherCode, 'state' => $this->state];
    }

    public function broadcastWhen(): bool
    {
        return ! app(BlockList::class)->isBlockedPair($this->toProfileId, $this->otherProfileId);
    }
}
