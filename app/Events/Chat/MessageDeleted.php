<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was unsent for everyone — `.message.deleted` on `chat.{conversation}` (PRD §9.4): both
 * open threads re-read and show "This message was deleted". The message id only.
 */
final class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /** @return array{id: string} */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }
}
