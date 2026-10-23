<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Events\Chat\MessageDeleted;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Support\Realtime\LiveSignal;
use Illuminate\Support\Facades\DB;

/**
 * An admin removing a chat message (A13 "delete message", A10 "content removal"): its text and
 * image are wiped and it reads "This message was removed by Oppam" for both members, live
 * (MessageDeleted, both inboxes). Call inside a transaction with the message row locked. A message
 * already unsent or removed is left as it is.
 */
final class MessageRemoval
{
    public function __construct(private readonly InboxSignals $inbox) {}

    /** @return bool whether the message was removed now */
    public function remove(Message $message): bool
    {
        if ($message->isGone()) {
            return false;
        }

        $message->body = null;
        $message->removed_by_admin_at = now();
        $message->save();
        // The file goes once the wipe is committed: a rollback must not leave a restored message without its photo.
        DB::afterCommit(static fn () => $message->clearMediaCollection(Message::IMAGE));

        LiveSignal::afterCommit(new MessageDeleted($message->conversation_id, $message->id));
        $thread = Conversation::query()->find($message->conversation_id);
        if ($thread !== null) {
            $this->inbox->changed($thread, ConversationParticipant::query()->where('conversation_id', $thread->id)
                ->pluck('profile_id')->map(fn (mixed $id): string => (string) $id)->all());
        }

        return true;
    }
}
