<?php

declare(strict_types=1);

namespace App\Events\Profile;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone viewed this member's profile (PRD §9.4, M03 acceptance "viewed me" live badge). Sent
 * to the viewed member's private user channel with today's count only — never who viewed
 * (viewer identity is a Gold+ feature, fetched through a normal request in M15).
 */
final class ProfileViewed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $viewedUserId,
        public readonly int $countToday,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->viewedUserId)];
    }

    public function broadcastAs(): string
    {
        return 'profile.viewed';
    }

    /** @return array{count_today: int} */
    public function broadcastWith(): array
    {
        return ['count_today' => $this->countToday];
    }
}
