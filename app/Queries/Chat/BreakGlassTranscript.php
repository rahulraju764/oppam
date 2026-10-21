<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\BreakGlassPageData;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Reads a conversation for a break-glass grant (PRD A13), by message-id cursor on (conversation_id,
 * id): the newest PAGE messages, a page before a given id, or the already-read range again. Read
 * only — ReadBreakGlassTranscript is the audited way in.
 */
final class BreakGlassTranscript
{
    public const PAGE = 50;

    public function __construct(private readonly ModeratedMessages $moderated) {}

    /** The PAGE messages before $beforeId (null: the newest ones). */
    public function page(Conversation $thread, ?string $beforeId = null): BreakGlassPageData
    {
        $rows = Message::query()->where('conversation_id', $thread->id)
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit(self::PAGE + 1)->get(ModeratedMessages::COLUMNS);
        $hasEarlier = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE)->reverse()->values();

        return new BreakGlassPageData($this->moderated->of($rows), $rows->first()?->id, $rows->last()?->id, $hasEarlier);
    }

    /** Every message from $fromId to $toId inclusive (what this grant already read). */
    public function range(Conversation $thread, string $fromId, string $toId): BreakGlassPageData
    {
        $rows = Message::query()->where('conversation_id', $thread->id)
            ->whereBetween('id', [$fromId, $toId])->orderBy('id')->get(ModeratedMessages::COLUMNS);
        $hasEarlier = Message::query()->where('conversation_id', $thread->id)->where('id', '<', $fromId)->exists();

        return new BreakGlassPageData($this->moderated->of($rows), $fromId, $toId, $hasEarlier);
    }
}
