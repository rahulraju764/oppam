<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\FlagContextData;
use App\Models\Message;
use App\Models\MessageFlag;

/**
 * The A13 review window for one flag (PRD A13 "only the flagged message with ±3 messages of
 * context"): the flagged message and at most CONTEXT messages before and after it in the SAME
 * thread, by id — never anything else of the conversation. Read only; RevealFlaggedMessage is the
 * audited way in, the review screen re-reads it only after that reveal.
 */
final class FlagContext
{
    public const CONTEXT = 3;

    public function __construct(private readonly ModeratedMessages $moderated) {}

    public function for(MessageFlag $flag): FlagContextData
    {
        $flagged = Message::query()->whereKey($flag->message_id)->firstOrFail(ModeratedMessages::COLUMNS);
        $before = Message::query()->where('conversation_id', $flagged->conversation_id)->where('id', '<', $flagged->id)
            ->orderByDesc('id')->limit(self::CONTEXT)->get(ModeratedMessages::COLUMNS)->reverse()->values();
        $after = Message::query()->where('conversation_id', $flagged->conversation_id)->where('id', '>', $flagged->id)
            ->orderBy('id')->limit(self::CONTEXT)->get(ModeratedMessages::COLUMNS);

        $rows = $this->moderated->of($before->concat([$flagged])->concat($after)->values(), $flagged->id);

        return new FlagContextData($rows[$before->count()], array_slice($rows, 0, $before->count()), array_slice($rows, $before->count() + 1), $flag->excerpt);
    }
}
