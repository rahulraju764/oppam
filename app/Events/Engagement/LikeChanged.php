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
 * Someone liked — or took back a like on — this member's profile (PRD §9.4 `ProfileLiked` /
 * `LikeRemoved`, both `.like.changed`). Goes to the liked member's private channel so "Liked me",
 * the dashboard strip and the like counts refresh; it is only a hint — the lists are re-read
 * through the normal, authorised queries. `from_code` is sent only when the recipient's plan lets
 * them see who liked them (or the like is mutual); an un-like never notifies (R-M06-5), it just
 * makes the lists re-read. Never delivered inside a blocked pair.
 */
final class LikeChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $toUserId,
        public readonly string $fromProfileId,
        public readonly string $toProfileId,
        public readonly ?string $fromCode,
        public readonly bool $liked,
        public readonly bool $isMutual,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->toUserId)];
    }

    public function broadcastAs(): string
    {
        return 'like.changed';
    }

    /** @return array{from_code: string|null, liked: bool, is_mutual: bool} */
    public function broadcastWith(): array
    {
        return ['from_code' => $this->fromCode, 'liked' => $this->liked, 'is_mutual' => $this->isMutual];
    }

    public function broadcastWhen(): bool
    {
        return ! app(BlockList::class)->isBlockedPair($this->fromProfileId, $this->toProfileId);
    }
}
