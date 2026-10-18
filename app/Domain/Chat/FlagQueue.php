<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Data\Chat\SafetyFindingData;
use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\MessageFlagStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * Puts chat messages in the A13 flagged queue (PRD A13) — the one place that writes message_flags
 * rows — and keeps the admins' "Flagged messages" badge live: after the transaction commits, the
 * waiting count goes out on admin.queues (AdminQueueCountChanged, best-effort).
 */
final class FlagQueue
{
    /**
     * ContentSafety's findings on a message just sent.
     *
     * @param  list<SafetyFindingData>  $findings
     */
    public function flagAutomatically(Message $message, array $findings): void
    {
        if ($findings === []) {
            return;
        }

        foreach ($findings as $finding) {
            $this->write($message, MessageFlagSource::Auto, $finding->rule, $finding->excerpt);
        }

        $this->markFlagged($message);
        $this->announce();
    }

    /** A message a member reported (one REPORT flag per message of the report). */
    public function flagReported(Message $message, Report $report): void
    {
        $this->write($message, MessageFlagSource::Report, MessageFlagRule::Reported, null, $report);
        $this->markFlagged($message);
    }

    /** Send the waiting count to the admin badges once the surrounding transaction commits. */
    public function announce(): void
    {
        // Counted after the commit, so the badge shows what the queue really holds (best-effort, as LiveSignal).
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(MessageFlag::QUEUE_KEY, MessageFlag::pendingCount())), report: true);
        });
    }

    private function write(Message $message, MessageFlagSource $source, MessageFlagRule $rule, ?string $excerpt, ?Report $report = null): void
    {
        $flag = new MessageFlag;
        $flag->message_id = $message->id;
        $flag->conversation_id = $message->conversation_id;
        $flag->sender_profile_id = $message->sender_profile_id;
        $flag->source = $source;
        $flag->rule = $rule;
        $flag->excerpt = $excerpt;
        $flag->report_id = $report?->id;
        $flag->reporter_profile_id = $report?->reporter_profile_id;
        $flag->status = MessageFlagStatus::Pending;
        $flag->save();
    }

    private function markFlagged(Message $message): void
    {
        if (! $message->is_flagged) {
            Message::query()->whereKey($message->id)->update(['is_flagged' => true]);
            $message->is_flagged = true;
        }
    }
}
