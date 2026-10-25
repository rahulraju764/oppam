<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Engagement\Connections;
use App\Domain\Safety\BlockList;
use App\Enums\ChatSendState;
use App\Enums\ConversationStatus;
use App\Enums\Entitlement;
use App\Enums\MessageType;
use App\Enums\ProfileStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Services\Entitlements\EntitlementService;

/**
 * Who may read and write which conversation (PRD M07, §9.3) — the one place for these rules; the
 * Actions, the screens and the channels ask here.
 *
 * Reading: a member sees a thread only while their profile is one of its two participants, the
 * thread isn't CLOSED and there is no block between the pair (either way) — otherwise it doesn't
 * exist for them (404).
 *
 * Writing (sendState): OPEN thread; the other member's profile still there (ACTIVE or HIDDEN, not
 * deleted / suspended); the pair still connected by an accepted interest (R-M07-1); and the plan
 * allows it — `chat_send` (paid), or a Free member's free reply (R-M07-3,
 * `free_replies_per_conversation`): only after the other member has written in this thread, and
 * only as many as the plan allows (owner decision 2026-10-15). Nobody writes to an unclaimed
 * bureau-managed client (§11A B.6, R-M13-34) until they claim the profile.
 */
final class ChatAccess
{
    public function __construct(
        private readonly BlockList $blocks,
        private readonly Connections $connections,
        private readonly EntitlementService $entitlements,
    ) {}

    /** The member's thread with this profile, or null when it doesn't exist for them. */
    public function threadWith(Profile $me, Profile $other): ?Conversation
    {
        if ($me->id === $other->id) {
            return null;
        }

        $thread = Conversation::query()->where('pair_key', Conversation::pairKey($me->id, $other->id))->first();

        return $thread !== null && $this->canRead($me, $thread, $other->id) ? $thread : null;
    }

    /** The other participant's profile id, or null when the member isn't a participant. */
    public function otherParticipantId(Profile $me, Conversation $thread): ?string
    {
        $ids = ConversationParticipant::query()->where('conversation_id', $thread->id)->pluck('profile_id')
            ->map(fn (mixed $id): string => (string) $id);

        if (! $ids->contains($me->id)) {
            return null;
        }

        return $ids->first(fn (string $id): bool => $id !== $me->id);
    }

    public function canRead(Profile $me, Conversation $thread, ?string $otherId = null): bool
    {
        $otherId ??= $this->otherParticipantId($me, $thread);

        if ($otherId === null || $thread->status === ConversationStatus::Closed) {
            return false;
        }

        return ConversationParticipant::query()->where('conversation_id', $thread->id)->whereIn('profile_id', [$me->id, $otherId])->count() === 2
            && ! $this->blocks->isBlockedPair($me->id, $otherId);
    }

    /** Whether the member may write in this thread now, and why not. Assumes canRead(). */
    public function sendState(Profile $me, Profile $other, Conversation $thread): ChatSendState
    {
        if ($thread->status !== ConversationStatus::Open) {
            return ChatSendState::Frozen;
        }

        if ($other->trashed() || ! in_array($other->status, [ProfileStatus::Active, ProfileStatus::Hidden], true)
            || ! $this->connections->isConnected($me, $other)) {
            return ChatSendState::Unavailable;
        }

        // An unclaimed bureau-managed client has no login to read or answer (R-M13-34).
        if ($other->isBrokerManaged()) {
            return ChatSendState::Managed;
        }

        if ($this->entitlements->allows($me, Entitlement::ChatSend)) {
            return ChatSendState::CanSend;
        }

        $free = $this->freeRepliesLeft($me, $other, $thread);

        return match (true) {
            $free === null, $free > 0 => ChatSendState::CanSend,
            $this->limit($me) !== 0 && ! $this->hasWritten($other, $thread) => ChatSendState::WaitForReply,
            default => ChatSendState::Upgrade,
        };
    }

    /**
     * Free replies the member has left in this thread: null = unlimited; 0 also while the other
     * member hasn't written yet (nothing to reply to). Only meaningful without `chat_send`.
     */
    public function freeRepliesLeft(Profile $me, Profile $other, Conversation $thread): ?int
    {
        $limit = $this->limit($me);

        if ($limit === null) {
            return null;
        }

        if ($limit === 0 || ! $this->hasWritten($other, $thread)) {
            return 0;
        }

        $sent = Message::query()->where('conversation_id', $thread->id)->where('sender_profile_id', $me->id)
            ->where('type', '!=', MessageType::System->value)->count();

        return max(0, $limit - $sent);
    }

    /** A member without `chat_send` writes only with free replies (R-M07-2 / R-M07-3). */
    public function sendsFree(Profile $me): bool
    {
        return ! $this->entitlements->allows($me, Entitlement::ChatSend);
    }

    private function limit(Profile $me): ?int
    {
        return $this->entitlements->limit($me, Entitlement::FreeRepliesPerConversation);
    }

    private function hasWritten(Profile $profile, Conversation $thread): bool
    {
        return Message::query()->where('conversation_id', $thread->id)->where('sender_profile_id', $profile->id)
            ->where('type', '!=', MessageType::System->value)->exists();
    }
}
