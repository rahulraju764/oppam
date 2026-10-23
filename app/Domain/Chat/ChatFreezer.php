<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Events\Chat\ConversationFrozen;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Support\Realtime\LiveSignal;

/**
 * Freezes and re-opens chat threads (R-M07-5): a FROZEN thread stays readable and nobody writes
 * in it (ChatAccess::sendState). The one place that changes a thread's frozen state — moderators
 * (FreezeConversation) and the automatic freeze after reports (ReportChatMessages) both come here.
 * Call inside a transaction with the thread row locked; ConversationFrozen goes out after commit.
 * A CLOSED thread is never touched.
 */
final class ChatFreezer
{
    public function __construct(
        private readonly ReportFreezeRule $rule,
        private readonly BlockList $blocks,
    ) {}

    /**
     * Freeze an OPEN thread. A moderator freezing a thread that froze automatically (after reports,
     * or because of a block) takes it over: the reason becomes MODERATION, so dismissing the reports
     * or an unblock later never opens it (decisions 2026-10-16 (d), 2026-10-23). Nothing goes out
     * live then — the thread was already read-only.
     *
     * @return bool whether the thread changed (OPEN → FROZEN, or REPORTS / BLOCK → MODERATION)
     */
    public function freeze(Conversation $thread, FreezeReason $reason): bool
    {
        if ($thread->status === ConversationStatus::Frozen && in_array($thread->frozen_reason, [FreezeReason::Reports, FreezeReason::Block], true) && $reason === FreezeReason::Moderation) {
            $thread->frozen_reason = FreezeReason::Moderation;
            $thread->save();

            return true;
        }

        if ($thread->status !== ConversationStatus::Open) {
            return false;
        }

        $thread->status = ConversationStatus::Frozen;
        $thread->frozen_at = now();
        $thread->frozen_reason = $reason;
        $thread->save();

        LiveSignal::afterCommit(new ConversationFrozen($thread->id, true));

        return true;
    }

    /**
     * Open again every thread of this member that the automatic freeze after reports closed
     * (FreezeReason::Reports) — the freeze is undone as one unit, never thread by thread only.
     * Threads frozen by a moderator stay frozen, and so does a thread whose OTHER member is still
     * over the rule (that member's own reports froze it too). Call inside a transaction.
     *
     * @return int how many threads were opened
     */
    public function unfreezeReportFrozen(string $profileId): int
    {
        $threadIds = ConversationParticipant::query()->where('profile_id', $profileId)->pluck('conversation_id')->all();
        $threads = Conversation::query()->whereKey($threadIds)->where('status', ConversationStatus::Frozen->value)
            ->where('frozen_reason', FreezeReason::Reports->value)->lockForUpdate()->get();
        $others = ConversationParticipant::query()->whereIn('conversation_id', $threads->modelKeys())
            ->where('profile_id', '!=', $profileId)->pluck('profile_id', 'conversation_id');
        $opened = 0;

        foreach ($threads as $thread) {
            $other = $others->get($thread->id);
            if ($other !== null && $this->rule->reached((string) $other)) {
                continue;
            }
            // A blocked pair's thread stays frozen as a block freeze (unblocking opens it then).
            if ($other !== null && $this->blocks->isBlockedPair($profileId, (string) $other)) {
                $thread->frozen_reason = FreezeReason::Block;
                $thread->save();

                continue;
            }
            $opened += $this->unfreeze($thread) ? 1 : 0;
        }

        return $opened;
    }

    /** @return bool whether the thread was FROZEN and is OPEN again */
    public function unfreeze(Conversation $thread): bool
    {
        if ($thread->status !== ConversationStatus::Frozen) {
            return false;
        }

        $thread->status = ConversationStatus::Open;
        $thread->frozen_at = null;
        $thread->frozen_reason = null;
        $thread->save();

        LiveSignal::afterCommit(new ConversationFrozen($thread->id, false));

        return true;
    }
}
