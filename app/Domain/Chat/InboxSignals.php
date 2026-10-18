<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Safety\BlockList;
use App\Events\Chat\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Queries\Chat\UnreadChats;
use App\Support\Realtime\LiveSignal;

/**
 * Tells participants' inboxes that a thread changed (PRD M07, §9.4 ConversationUpdated): one event
 * per participant, built from the database after the change — their unread count, their total for
 * the badge, the other member's code and first name, the last message's preview. Best-effort and
 * after commit (LiveSignal): the inbox re-reads from the database anyway. Nothing is sent inside a
 * blocked pair.
 */
final class InboxSignals
{
    public function __construct(
        private readonly BlockList $blocks,
        private readonly UnreadChats $unread,
    ) {}

    /** @param  list<string>  $profileIds  the participants to tell (one or both) */
    public function changed(Conversation $thread, array $profileIds): void
    {
        $participants = ConversationParticipant::query()->where('conversation_id', $thread->id)->get()->keyBy('profile_id');
        $profiles = Profile::withTrashed()->whereKey($participants->keys()->all())->get(['id', 'code', 'first_name'])->keyBy('id');

        if ($participants->count() !== 2 || $this->blocks->isBlockedPair(...$participants->keys()->map(fn (mixed $id): string => (string) $id)->all())) {
            return;
        }

        $last = $thread->last_message_id !== null ? Message::query()->find($thread->last_message_id) : null;

        foreach ($profileIds as $profileId) {
            $mine = $participants->get($profileId);
            $me = $profiles->get($profileId);
            $other = $profiles->first(fn (Profile $p): bool => $p->id !== $profileId);

            if ($mine === null || $me === null || $other === null) {
                continue;
            }

            LiveSignal::afterCommit(new ConversationUpdated(
                profileId: $profileId,
                withCode: $other->code,
                name: $other->first_name,
                preview: $last !== null ? MessageText::preview($last->type, $last->body, $last->deleted_for_all_at !== null, $last->removed_by_admin_at !== null) : '',
                at: $thread->last_message_at?->toIso8601String(),
                unread: $mine->unread_count,
                totalUnread: $this->unread->total($me),
                fromMe: $last?->sender_profile_id === $profileId,
                muted: $mine->is_muted,
            ));
        }
    }
}
