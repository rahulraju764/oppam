<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Domain\Safety\BlockList;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message in a thread — `.message.sent` on `chat.{conversation}` (PRD §9.4), sent at once
 * (chat events don't wait in a queue). Both participants' open threads receive it — the sender's
 * other tabs too (§9.7.5); the sending tab recognises its own optimistic bubble by `client_id`.
 * The payload is what the thread shows: the message id (its time-sorted cursor), the sender's
 * CODE, type, body and time — never a profile id, phone or email. Never delivered inside a
 * blocked pair (the channel itself also refuses a blocked pair).
 */
final class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $clientId,
        public readonly string $senderProfileId,
        public readonly string $recipientProfileId,
        public readonly string $senderCode,
        public readonly string $type,
        public readonly string $body,
        public readonly string $createdAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array{id: string, client_id: string, sender_code: string, type: string, body: string, created_at: string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'client_id' => $this->clientId,
            'sender_code' => $this->senderCode,
            'type' => $this->type,
            'body' => $this->body,
            'created_at' => $this->createdAt,
        ];
    }

    public function broadcastWhen(): bool
    {
        return ! app(BlockList::class)->isBlockedPair($this->senderProfileId, $this->recipientProfileId);
    }
}
