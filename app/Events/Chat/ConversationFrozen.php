<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A thread was frozen or opened again — `.conversation.frozen` on `chat.{conversation}` (R-M07-5,
 * PRD A13 "freeze conversation (live ConversationFrozen)"): both open threads re-read, and the
 * composer turns read-only (or back). Sent at once (ShouldBroadcastNow): "within 2 s". Payload:
 * whether it is frozen — nothing else.
 */
final class ConversationFrozen implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly bool $frozen,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.frozen';
    }

    /** @return array{frozen: bool} */
    public function broadcastWith(): array
    {
        return ['frozen' => $this->frozen];
    }
}
