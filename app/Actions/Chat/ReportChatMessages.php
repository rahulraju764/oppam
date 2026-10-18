<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Chat\ChatFreezer;
use App\Domain\Chat\FlagQueue;
use App\Domain\Chat\ReportFreezeRule;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\MessageType;
use App\Enums\ReportReason;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Report the other member from a chat thread (PRD M09 "Report with reasons + message selection
 * auto-attached", A13 "user-reported messages"):
 *  - the thread must exist for the member (participant, not CLOSED, no block) — else 404;
 *  - a reason from the M09 list; details up to 500 characters (required for "something else");
 *  - 1 – 10 messages, each one the OTHER member wrote in this thread (not SYSTEM, not unsent or
 *    removed) — anything else is refused and nothing is written;
 *  - `chat.reports_max_per_hour` per member (429); one OPEN chat report per member reported (409).
 * In one transaction: the report, its messages, one REPORT flag per message in the A13 queue.
 * Reporting freezes nothing (M09) — unless the reported member has now been reported from chat by
 * `chat.auto_freeze_reports` (3) different members within `chat.auto_freeze_window_days` (30, dismissed
 * reports don't count): then all of their OPEN threads freeze pending review (decisions 2026-10-16),
 * audited as a system action. The reported member is never told. Never during an admin support session.
 */
final class ReportChatMessages
{
    public const MAX_MESSAGES = 10;

    public function __construct(
        private readonly ChatAccess $access,
        private readonly FlagQueue $flags,
        private readonly ChatFreezer $freezer,
        private readonly ReportFreezeRule $rule,
        private readonly Impersonation $impersonation,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $messageIds
     *
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException no such thread for this member
     * @throws ValidationException bad reason / details / message selection
     * @throws ReportRefused too many reports, or already reported
     */
    public function handle(User $member, Profile $other, string $reason, ?string $details, array $messageIds): Report
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        $thread = $me === null ? null : $this->access->threadWith($me, $other);
        if ($me === null || $thread === null) {
            throw new NotFoundHttpException;
        }

        $details = $details === null || trim($details) === '' ? null : trim($details);
        $data = Validator::make(['reason' => $reason, 'details' => $details, 'messages' => $messageIds], [
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:500', Rule::requiredIf($reason === ReportReason::Other->value)],
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i', 'distinct'],
        ], [
            'messages.required' => __('Choose at least one message to report.'),
            'messages.min' => __('Choose at least one message to report.'),
        ], ['reason' => __('reason'), 'details' => __('details'), 'messages' => __('messages')])->validate();

        $ids = array_map('strtolower', $data['messages']);
        $messages = Message::query()->where('conversation_id', $thread->id)->whereKey($ids)
            ->where('sender_profile_id', $other->id)->where('type', '!=', MessageType::System->value)
            ->whereNull('deleted_for_all_at')->whereNull('removed_by_admin_at')->get();
        if ($messages->count() !== count($ids)) {
            throw ValidationException::withMessages(['messages' => __('Choose messages :name sent you in this conversation.', ['name' => $other->first_name])]);
        }

        $key = 'chat-report:'.$me->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::ChatReportsMaxPerHour))) {
            throw ReportRefused::tooMany();
        }
        $this->limiter->hit($key, 3600);

        return DB::transaction(function () use ($me, $other, $thread, $data, $details, $messages): Report {
            // Both profile rows, in id order (two members reporting each other can't deadlock): the
            // reporter's lock keeps one open chat report per pair; the REPORTED member's lock serialises
            // every report about them, so two reports arriving together can't both count one too few
            // and miss the automatic freeze (DecideMessageFlag takes the same lock before it re-counts).
            Profile::query()->whereKey([$me->id, $other->id])->orderBy('id')->lockForUpdate()->get(['id']);
            if (Report::query()->where('reporter_profile_id', $me->id)->where('reported_profile_id', $other->id)
                ->where('source', ReportSource::Chat->value)->where('status', ReportStatus::Open->value)->exists()) {
                throw ReportRefused::alreadyReported();
            }

            $report = new Report;
            $report->reporter_profile_id = $me->id;
            $report->reported_profile_id = $other->id;
            $report->source = ReportSource::Chat;
            $report->reason = ReportReason::from($data['reason']);
            $report->details = $details;
            $report->conversation_id = $thread->id;
            $report->status = ReportStatus::Open;
            $report->save();
            $report->messages()->attach($messages->modelKeys());

            foreach ($messages as $message) {
                $this->flags->flagReported($message, $report);
            }
            $this->flags->announce();

            $this->autoFreeze($other);

            return $report;
        });
    }

    /** M09: reported from chat by enough different members → freeze all their OPEN threads. */
    private function autoFreeze(Profile $reported): void
    {
        if (! $this->rule->reached($reported->id)) {
            return;
        }

        $threadIds = ConversationParticipant::query()->where('profile_id', $reported->id)->pluck('conversation_id')->all();
        $frozen = 0;
        foreach (Conversation::query()->whereKey($threadIds)->where('status', ConversationStatus::Open->value)->lockForUpdate()->get() as $thread) {
            $frozen += $this->freezer->freeze($thread, FreezeReason::Reports) ? 1 : 0;
        }

        if ($frozen > 0) {
            $this->audit->record('chat.auto_frozen', $reported, after: ['threads_frozen' => $frozen, 'reporters' => $this->rule->reporters($reported->id)], subjectLabel: $reported->code);
        }
    }
}
