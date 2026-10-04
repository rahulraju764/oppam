<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Broadcasting\Concerns\ChecksMember;
use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Profile;

/**
 * `chat.{conversation}` (PRD §9.3): messages, receipts, deletions. Only an active member whose
 * profile is a participant, while the thread isn't CLOSED and there is no block between the two
 * (either direction). PresenceChatChannel applies the same rule to `presence-chat.{conversation}`.
 */
final class ChatChannel
{
    use ChecksMember;

    public function __construct(private readonly BlockList $blocks) {}

    public function join(mixed $user, string $conversation): bool
    {
        return $this->participant($user, $conversation) !== null;
    }

    /** The member's profile when they may follow this thread live; null otherwise. */
    public function participant(mixed $user, string $conversationId): ?Profile
    {
        $member = $this->activeMember($user);
        $me = $member === null ? null : $this->profileOf($member);
        $thread = $me === null ? null : Conversation::query()->whereKey($conversationId)->first();

        if ($me === null || $thread === null || $thread->status === ConversationStatus::Closed) {
            return null;
        }

        $ids = ConversationParticipant::query()->where('conversation_id', $thread->id)->pluck('profile_id')
            ->map(fn (mixed $id): string => (string) $id);
        if (! $ids->contains($me->id)) {
            return null;
        }

        $hidden = $this->blocks->hiddenFrom($me);

        return $ids->contains(fn (string $id): bool => in_array($id, $hidden, true)) ? null : $me;
    }
}
