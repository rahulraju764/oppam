<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\MessageData;
use App\Domain\Chat\MessageText;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * The newest messages of a thread as one member sees them (PRD M07), oldest → newest, one query.
 * Load-earlier (cursor pagination by id) and reconnect back-fill (loadSince) come in P4.2.
 * Ticks only on the member's own messages; blue only when `$blueTicks` (both members have read
 * receipts on, R-M07-7) — otherwise a read message shows as delivered.
 */
final class ThreadWindow
{
    public const PER_PAGE = 30;

    /** @return list<MessageData> */
    public function latest(Conversation $thread, Profile $me, bool $blueTicks, int $limit = self::PER_PAGE): array
    {
        $timezone = (string) config('oppam.display_timezone');

        return Message::query()->where('conversation_id', $thread->id)
            ->orderByDesc('id')->limit($limit)
            ->get(['id', 'client_id', 'sender_profile_id', 'type', 'body', 'delivered_at', 'read_at', 'deleted_for_all_at', 'created_at'])
            ->reverse()->values()
            ->map(function (Message $message) use ($me, $blueTicks, $timezone): MessageData {
                $mine = $message->sender_profile_id === $me->id;

                return new MessageData(
                    id: $message->id,
                    clientId: $message->client_id,
                    mine: $mine,
                    system: $message->type === MessageType::System,
                    text: MessageText::display($message->type, $message->body, $message->deleted_for_all_at !== null),
                    at: CarbonImmutable::parse($message->created_at ?? 'now')->setTimezone($timezone),
                    tick: $mine ? match (true) {
                        $message->read_at !== null && $blueTicks => MessageData::READ,
                        $message->read_at !== null, $message->delivered_at !== null => MessageData::DELIVERED,
                        default => MessageData::SENT,
                    } : null,
                );
            })->all();
    }
}
