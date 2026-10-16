<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\InboxSignals;
use App\Enums\MessageType;
use App\Enums\SettingKey;
use App\Events\Chat\MessageDeleted;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use App\Support\Realtime\LiveSignal;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unsend a message for everyone (PRD M07: "unsend within 60 min — This message was deleted").
 * Only the member who sent it, only a TEXT or IMAGE message, only within
 * `chat.unsend_window_minutes` of sending, only once. The row stays as a tombstone (the thread's
 * order and the other member's read state don't change); its text is wiped and its image file
 * deleted — nothing of it is kept. Allowed in a frozen thread (removing content is always fine).
 * After commit: MessageDeleted on the thread, both inboxes re-read (the preview may have been it).
 * A message id that isn't the member's own, in a thread they can read, is a 404.
 */
final class UnsendMessage
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly InboxSignals $inbox,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException not the member's own message in a thread they can read
     * @throws ChatRefused past the window, already unsent, or a SYSTEM message
     */
    public function handle(User $member, string $messageId): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        $message = $me === null || preg_match('/^[0-9a-z]{26}$/i', $messageId) !== 1 ? null
            : Message::query()->whereKey(strtolower($messageId))->where('sender_profile_id', $me->id)->first();
        $thread = $message?->conversation;

        if ($me === null || $message === null || $thread === null || ! $this->access->canRead($me, $thread)) {
            throw new NotFoundHttpException;
        }

        DB::transaction(function () use ($message, $me): void {
            $locked = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if (! self::allowed($locked, $me)) {
                throw ChatRefused::cannotUnsend();
            }

            $locked->body = null;
            $locked->deleted_for_all_at = now();
            $locked->save();
            $locked->clearMediaCollection(Message::IMAGE);

            LiveSignal::afterCommit(new MessageDeleted($locked->conversation_id, $locked->id));
        });

        $other = $this->access->otherParticipantId($me, $thread);
        $this->inbox->changed($thread->refresh(), array_values(array_filter([$me->id, $other])));
    }

    /** Whether this member may still unsend this message now (for the bubble's menu). */
    public static function allowed(Message $message, Profile $me): bool
    {
        return $message->sender_profile_id === $me->id && $message->deleted_for_all_at === null
            && $message->type !== MessageType::System && $message->created_at !== null
            && $message->created_at->gte(now()->subMinutes(Settings::int(SettingKey::ChatUnsendWindowMinutes)));
    }
}
