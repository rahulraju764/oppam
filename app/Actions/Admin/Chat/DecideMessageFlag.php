<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Actions\Admin\Members\SuspendMember;
use App\Domain\Chat\ChatFreezer;
use App\Domain\Chat\FlagQueue;
use App\Domain\Chat\MessageRemoval;
use App\Domain\Chat\ReportFreezeRule;
use App\Domain\Safety\AbuseCases;
use App\Enums\FreezeReason;
use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagStatus;
use App\Enums\ReportStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Chat\MessageFlagUnavailable;
use App\Models\AdminUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Chat\ChatWarning;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A moderator's decision on a flagged chat message (PRD A13). Needs `chat.view_flagged` plus the
 * decision's own permission (MessageFlagDecision::permission: chat.moderate, chat.freeze,
 * members.suspend). Only an open flag can be decided; an ESCALATED one only by a super admin.
 *  - Dismiss: nothing happens to the message.
 *  - Delete message: its text and image are wiped, it reads "This message was removed by Oppam"
 *    for both members, live (MessageDeleted); every open flag on that message is closed with it.
 *  - Warn sender: a fixed-wording ChatWarning (in-app + email), never quoting the message.
 *  - Freeze conversation: the thread turns read-only for both, live (ConversationFrozen).
 *  - Suspend sender: the A03 flow (SuspendMember: typed reason, sessions end, audited).
 *  - Escalate: waits for a super admin, and the sender's A10 abuse case becomes P0 (AbuseCases).
 * Optional note (≤ 500). Audited ("chat.flag.decided"); the flag's report closes when its flags are
 * decided (ACTIONED if any led to an action, DISMISSED if all were dismissed). When a dismissal takes the
 * reported member back below the auto-freeze rule, every thread the automatic freeze closed opens again
 * ("chat.auto_unfrozen"). The queue badge updates live.
 */
final class DecideMessageFlag
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChatFreezer $freezer,
        private readonly ReportFreezeRule $rule,
        private readonly FlagQueue $queue,
        private readonly MessageRemoval $removal,
        private readonly SuspendMember $suspend,
        private readonly AbuseCases $cases,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MessageFlagUnavailable
     * @throws MemberStateConflict the sender is already suspended / deleted
     */
    public function handle(AdminUser $admin, MessageFlag $flag, MessageFlagDecision $decision, ?string $note = null): void
    {
        Gate::forUser($admin)->authorize('chat.view_flagged');
        Gate::forUser($admin)->authorize($decision->permission());

        $note = $note === null || trim($note) === '' ? null : trim($note);
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:500']])->validate();
        if ($decision === MessageFlagDecision::SuspendSender) {
            // SuspendMember needs the typed reason (A03); the moderator's note is it.
            Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:5']],
                ['note.required' => __('Type the reason for the suspension.')])->validate();
        }

        $warn = DB::transaction(function () use ($admin, $flag, $decision, $note): ?User {
            // The sender's (= reported member's) profile row first, as ReportChatMessages does: a report
            // arriving meanwhile can't slip between the re-count below and the automatic unfreeze.
            if ($flag->sender_profile_id !== null) {
                Profile::withTrashed()->whereKey($flag->sender_profile_id)->lockForUpdate()->first(['id']);
            }

            $locked = MessageFlag::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($admin, $locked, $decision);
            $before = $locked->status->value;

            $message = Message::query()->whereKey($locked->message_id)->lockForUpdate()->firstOrFail();
            $sender = $locked->sender_profile_id === null ? null : Profile::withTrashed()->find($locked->sender_profile_id);
            $warn = null;

            match ($decision) {
                MessageFlagDecision::RemoveMessage => $this->remove($message),
                MessageFlagDecision::FreezeConversation => $this->freeze($message),
                MessageFlagDecision::SuspendSender => $this->suspend->handle($admin, $sender->user ?? throw MemberStateConflict::notActive(), (string) $note),
                MessageFlagDecision::WarnSender => $warn = $sender?->user,
                MessageFlagDecision::Escalate => $locked->sender_profile_id !== null ? $this->cases->escalateProfile($locked->sender_profile_id) : null,
                MessageFlagDecision::Dismiss => null,
            };

            // Deleting the message settles every open flag on it; anything else settles this flag.
            $closing = $decision === MessageFlagDecision::RemoveMessage
                ? MessageFlag::query()->where('message_id', $message->id)->open()->lockForUpdate()->get()
                : collect([$locked]);
            foreach ($closing as $row) {
                $row->status = $decision->resultingStatus();
                $row->decision = $decision;
                $row->decision_note = $note;
                $row->decided_by_admin_id = $admin->id;
                $row->decided_at = $decision === MessageFlagDecision::Escalate ? null : now();
                $row->save();
            }

            $this->settleReports($closing->pluck('report_id')->filter()->unique()->values()->all());

            $this->audit->record('chat.flag.decided', $locked, before: ['status' => $before],
                after: ['decision' => $decision->value, 'status' => $decision->resultingStatus()->value, 'rule' => $locked->rule->value, 'flags_closed' => $closing->count()],
                reason: $note, actor: $admin, subjectLabel: $sender?->code);

            $this->queue->announce();

            return $warn;
        });

        $warn?->notify(new ChatWarning);
    }

    private function assertDecidable(AdminUser $admin, MessageFlag $flag, MessageFlagDecision $decision): void
    {
        if (! $flag->status->isOpen() || ($decision === MessageFlagDecision::Escalate && $flag->status === MessageFlagStatus::Escalated)) {
            throw MessageFlagUnavailable::decided();
        }

        if ($flag->status === MessageFlagStatus::Escalated && ! $admin->isSuperAdmin()) {
            throw MessageFlagUnavailable::escalated();
        }
    }

    private function remove(Message $message): void
    {
        $this->removal->remove($message);
    }

    private function freeze(Message $message): void
    {
        $thread = Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->first();
        if ($thread !== null) {
            $this->freezer->freeze($thread, FreezeReason::Moderation);
        }
    }

    /** @param  list<string>  $reportIds */
    private function settleReports(array $reportIds): void
    {
        foreach (Report::query()->whereKey($reportIds)->lockForUpdate()->get() as $report) {
            $statuses = MessageFlag::query()->where('report_id', $report->id)->pluck('status');
            if ($statuses->contains(fn (MessageFlagStatus $status): bool => $status->isOpen())) {
                continue;
            }

            $report->status = $statuses->every(fn (MessageFlagStatus $status): bool => $status === MessageFlagStatus::Dismissed)
                ? ReportStatus::Dismissed : ReportStatus::Actioned;
            $report->decided_at = now();
            $report->save();

            // The automatic freeze is undone as one unit once dismissals take the member below the rule.
            if ($report->status === ReportStatus::Dismissed && ! $this->rule->reached($report->reported_profile_id)) {
                $opened = $this->freezer->unfreezeReportFrozen($report->reported_profile_id);
                if ($opened > 0) {
                    $this->audit->record('chat.auto_unfrozen', $report, after: ['threads_opened' => $opened],
                        reason: __('Reports dismissed'), subjectLabel: Profile::withTrashed()->whereKey($report->reported_profile_id)->value('code'));
                }
            }
        }
    }
}
