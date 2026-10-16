<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Messages reached the recipient's open site — `.message.delivered` on `chat.{conversation}`
 * (PRD §9.4): the sender's ✓ becomes ✓✓. "Delivered" = the recipient had a member page open
 * when the message arrived, or opened one later (MarkMessagesDelivered).
 */
final class MessagesDelivered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $upToMessageId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.delivered';
    }

    /** @return array{up_to_message_id: string} */
    public function broadcastWith(): array
    {
        return ['up_to_message_id' => $this->upToMessageId];
    }
}
