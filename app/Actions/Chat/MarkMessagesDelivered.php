<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Events\Chat\MessagesDelivered;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Services\Admin\Impersonation;
use App\Support\Realtime\LiveSignal;

/**
 * The member's site is open, so the messages waiting for them have reached them (PRD M07 ticks:
 * ✓ sent → ✓✓ delivered). Run by the chat badge when a page loads with unread messages and when a
 * new one arrives live. Only the member's own threads (participant rows by their profile id), only
 * messages from the other member without `delivered_at`; each thread that changed tells its
 * sender (MessagesDelivered). Skipped — not refused — during an admin support session: the admin's
 * screen is not the member's device. Idempotent: a second run finds nothing.
 *
 * @return int messages newly marked delivered
 */
final class MarkMessagesDelivered
{
    public function __construct(
        private readonly BlockList $blocks,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Profile $me): int
    {
        if ($this->impersonation->isActive()) {
            return 0;
        }

        $threadIds = ConversationParticipant::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->where('conversation_participants.profile_id', $me->id)
            ->where('conversation_participants.unread_count', '>', 0)
            ->where('conversations.status', '!=', ConversationStatus::Closed->value)
            ->pluck('conversation_participants.conversation_id')
            ->map(fn (mixed $id): string => (string) $id)->all();

        if ($threadIds === []) {
            return 0;
        }

        $hidden = $this->blocks->hiddenFrom($me);
        $pending = fn () => Message::query()->whereIn('conversation_id', $threadIds)->whereNull('delivered_at')
            ->whereNotNull('sender_profile_id')->where('sender_profile_id', '!=', $me->id)
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('sender_profile_id', $hidden));

        // The newest waiting message per thread (one grouped query), then one UPDATE for them all.
        $newest = $pending()->groupBy('conversation_id')->selectRaw('conversation_id, MAX(id) as up_to')->pluck('up_to', 'conversation_id');
        if ($newest->isEmpty()) {
            return 0;
        }

        $marked = $pending()->update(['delivered_at' => now()]);

        foreach ($newest as $threadId => $upTo) {
            LiveSignal::afterCommit(new MessagesDelivered((string) $threadId, (string) $upTo));
        }

        return $marked;
    }
}
