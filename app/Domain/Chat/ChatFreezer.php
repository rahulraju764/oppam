<?php

declare(strict_types=1);

namespace App\Domain\Chat;

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
    /** @return bool whether the thread was OPEN and is FROZEN now */
    public function freeze(Conversation $thread, FreezeReason $reason): bool
    {
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
     * Threads frozen by a moderator stay frozen. Call inside a transaction.
     *
     * @return int how many threads were opened
     */
    public function unfreezeReportFrozen(string $profileId): int
    {
        $threadIds = ConversationParticipant::query()->where('profile_id', $profileId)->pluck('conversation_id')->all();
        $opened = 0;

        foreach (Conversation::query()->whereKey($threadIds)->where('status', ConversationStatus::Frozen->value)
            ->where('frozen_reason', FreezeReason::Reports->value)->lockForUpdate()->get() as $thread) {
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
