<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\FlagContextData;
use App\Data\Chat\ModeratedMessageData;
use App\Domain\Chat\MessageText;
use App\Enums\MessageType;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The A13 review window for one flag (PRD A13 "only the flagged message with ±3 messages of
 * context"): the flagged message and at most CONTEXT messages before and after it in the SAME
 * thread, by id — never anything else of the conversation. Read only; RevealFlaggedMessage is the
 * audited way in, the review screen re-reads it only after that reveal.
 */
final class FlagContext
{
    public const CONTEXT = 3;

    private const COLUMNS = ['id', 'conversation_id', 'sender_profile_id', 'type', 'body', 'deleted_for_all_at', 'removed_by_admin_at', 'created_at'];

    public function for(MessageFlag $flag): FlagContextData
    {
        $flagged = Message::query()->whereKey($flag->message_id)->firstOrFail(self::COLUMNS);
        $before = Message::query()->where('conversation_id', $flagged->conversation_id)->where('id', '<', $flagged->id)
            ->orderByDesc('id')->limit(self::CONTEXT)->get(self::COLUMNS)->reverse()->values();
        $after = Message::query()->where('conversation_id', $flagged->conversation_id)->where('id', '>', $flagged->id)
            ->orderBy('id')->limit(self::CONTEXT)->get(self::COLUMNS);

        $codes = Profile::withTrashed()
            ->whereKey($before->concat([$flagged])->concat($after)->pluck('sender_profile_id')->filter()->unique()->all())
            ->pluck('code', 'id');
        $data = fn (Message $message): ModeratedMessageData => $this->data($message, $codes, $message->id === $flagged->id);

        return new FlagContextData($data($flagged), $before->map($data)->values()->all(), $after->map($data)->values()->all(), $flag->excerpt);
    }

    /** @param  Collection<string, string>  $codes */
    private function data(Message $message, Collection $codes, bool $isFlagged): ModeratedMessageData
    {
        return new ModeratedMessageData(
            id: $message->id,
            senderLabel: $message->sender_profile_id === null ? 'Oppam' : (string) $codes->get($message->sender_profile_id, '—'),
            text: MessageText::display($message->type, $message->body, $message->deleted_for_all_at !== null, $message->removed_by_admin_at !== null),
            at: CarbonImmutable::parse($message->created_at ?? 'now')->setTimezone((string) config('oppam.display_timezone')),
            isFlagged: $isFlagged,
            isImage: $message->type === MessageType::Image && ! $message->isGone(),
            gone: $message->isGone(),
        );
    }
}
