<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One member's view of a thread changed — `.conversation.updated` on `inbox.{profile}` (PRD §9.4):
 * the inbox re-orders, the chat badge and the tab title take `total_unread`, and a message from the
 * other member pops a toast where that thread isn't open. Built per participant by InboxSignals.
 * The thread is named by the OTHER member's code (URLs use codes); `name` is their first name for
 * the toast. The inbox channel is the owner's alone, so the preview may be shown.
 */
final class ConversationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $profileId,
        public readonly string $withCode,
        public readonly string $name,
        public readonly string $preview,
        public readonly ?string $at,
        public readonly int $unread,
        public readonly int $totalUnread,
        public readonly bool $fromMe,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox.'.$this->profileId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /** @return array{with_code: string, name: string, preview: string, at: string|null, unread: int, total_unread: int, from_me: bool} */
    public function broadcastWith(): array
    {
        return [
            'with_code' => $this->withCode,
            'name' => $this->name,
            'preview' => $this->preview,
            'at' => $this->at,
            'unread' => $this->unread,
            'total_unread' => $this->totalUnread,
            'from_me' => $this->fromMe,
        ];
    }
}
