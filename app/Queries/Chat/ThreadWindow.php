<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Actions\Chat\UnsendMessage;
use App\Data\Chat\MessageData;
use App\Domain\Chat\MessageText;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A thread's messages as one member sees them (PRD M07), oldest → newest, by id CURSOR:
 *  - first open: the newest PER_PAGE;
 *  - then everything from the oldest message on screen (`$fromId`) onwards — so a re-render, a
 *    live event or a socket reconnect re-reads every newer message in order (the reconnect
 *    back-fill, §9.7.1), and "load earlier" moves the cursor back by PER_PAGE (earlierCursor()).
 * A window is capped at MAX messages (the newest of them). Ticks only on the member's own
 * messages; blue only when `$blueTicks` (both members have read receipts on, R-M07-7).
 */
final class ThreadWindow
{
    public const PER_PAGE = 30;

    public const MAX = 600;

    /** @return array{messages: list<MessageData>, oldestId: string|null, hasEarlier: bool} */
    public function window(Conversation $thread, Profile $me, bool $blueTicks, ?string $fromId = null): array
    {
        $query = Message::query()->where('conversation_id', $thread->id)
            ->with('media')
            ->when($fromId !== null, fn ($q) => $q->where('id', '>=', $fromId))
            ->orderByDesc('id')->limit($fromId === null ? self::PER_PAGE : self::MAX)
            ->select(['id', 'conversation_id', 'client_id', 'sender_profile_id', 'type', 'body', 'delivered_at', 'read_at', 'deleted_for_all_at', 'removed_by_admin_at', 'created_at']);

        /** @var Collection<int, Message> $rows */
        $rows = $query->get()->reverse()->values();
        $oldest = $rows->first()?->id;
        $hasEarlier = $oldest !== null && Message::query()->where('conversation_id', $thread->id)->where('id', '<', $oldest)->exists();

        return [
            'messages' => $rows->map(fn (Message $message): MessageData => $this->data($message, $me, $blueTicks))->all(),
            'oldestId' => $oldest,
            'hasEarlier' => $hasEarlier,
        ];
    }

    /** The cursor PER_PAGE messages before `$oldestId` (null = nothing earlier). */
    public function earlierCursor(Conversation $thread, string $oldestId): ?string
    {
        $ids = Message::query()->where('conversation_id', $thread->id)->where('id', '<', $oldestId)
            ->orderByDesc('id')->limit(self::PER_PAGE)->pluck('id');

        return $ids->isEmpty() ? null : (string) $ids->last();
    }

    private function data(Message $message, Profile $me, bool $blueTicks): MessageData
    {
        $mine = $message->sender_profile_id === $me->id;
        $deleted = $message->isGone();
        $image = $message->type === MessageType::Image && ! $deleted && $message->media->isNotEmpty();

        return new MessageData(
            id: $message->id,
            clientId: $message->client_id,
            mine: $mine,
            system: $message->type === MessageType::System,
            text: MessageText::display($message->type, $message->body, $deleted, $message->removed_by_admin_at !== null),
            at: CarbonImmutable::parse($message->created_at ?? 'now')->setTimezone((string) config('oppam.display_timezone')),
            tick: $mine ? match (true) {
                $message->read_at !== null && $blueTicks => MessageData::READ,
                $message->read_at !== null, $message->delivered_at !== null => MessageData::DELIVERED,
                default => MessageData::SENT,
            } : null,
            deleted: $deleted,
            imageUrl: $image ? route('member.chat.image', ['message' => $message->id, 'size' => 'preview']) : null,
            canUnsend: $mine && UnsendMessage::allowed($message, $me),
        );
    }
}
