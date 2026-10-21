<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\ModeratedMessageData;
use App\Domain\Chat\MessageText;
use App\Enums\MessageType;
use App\Models\Message;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Chat messages as a moderator sees them on an A13 screen (flag review after the audited reveal,
 * break-glass transcript): sender by profile code (SYSTEM → "Oppam"), the stored text (masked where
 * ContentSafety masked it; a photo reads "Photo"), IST time. One query for the codes.
 */
final class ModeratedMessages
{
    /** Columns every caller selects. */
    public const COLUMNS = ['id', 'conversation_id', 'sender_profile_id', 'type', 'body', 'is_flagged', 'deleted_for_all_at', 'removed_by_admin_at', 'created_at'];

    /**
     * @param  Collection<int, Message>  $messages
     * @param  string|null  $flaggedId  the message to mark as flagged (a flag review); null = each message's own flag
     * @return list<ModeratedMessageData> in the given order
     */
    public function of(Collection $messages, ?string $flaggedId = null): array
    {
        $codes = Profile::withTrashed()->whereKey($messages->pluck('sender_profile_id')->filter()->unique()->all())->pluck('code', 'id');
        $zone = (string) config('oppam.display_timezone');

        return $messages->map(fn (Message $message): ModeratedMessageData => new ModeratedMessageData(
            id: $message->id,
            senderLabel: $message->sender_profile_id === null ? 'Oppam' : (string) $codes->get($message->sender_profile_id, '—'),
            text: MessageText::display($message->type, $message->body, $message->deleted_for_all_at !== null, $message->removed_by_admin_at !== null),
            at: CarbonImmutable::parse($message->created_at ?? 'now')->setTimezone($zone),
            isFlagged: $flaggedId !== null ? $message->id === $flaggedId : (bool) $message->is_flagged,
            isImage: $message->type === MessageType::Image && ! $message->isGone(),
            gone: $message->isGone(),
        ))->values()->all();
    }
}
