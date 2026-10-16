<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant read the thread up to a message — `.message.read` on `chat.{conversation}` (PRD
 * §9.4): the other side's ticks turn blue. Sent only when the reader has read receipts on
 * (R-M07-7; MarkConversationRead decides). Carries the reader's CODE only.
 */
final class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $readerCode,
        public readonly string $upToMessageId,
        public readonly string $readAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    /** @return array{reader_code: string, up_to_message_id: string, read_at: string} */
    public function broadcastWith(): array
    {
        return ['reader_code' => $this->readerCode, 'up_to_message_id' => $this->upToMessageId, 'read_at' => $this->readAt];
    }
}
