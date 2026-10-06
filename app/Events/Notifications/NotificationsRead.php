<?php

declare(strict_types=1);

namespace App\Events\Notifications;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The member read (or deleted) notifications in one tab: every other open tab sets its bell to the
 * new unread count (M08 acceptance "decrements across all open tabs"). Only a number.
 */
final class NotificationsRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $userId,
        public readonly int $unreadCount,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notifications.read';
    }

    /** @return array{unread_count: int} */
    public function broadcastWith(): array
    {
        return ['unread_count' => $this->unreadCount];
    }
}
