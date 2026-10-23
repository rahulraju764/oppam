<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Actions\Admin\Members\SetMemberProfileHidden;
use App\Actions\Admin\Members\SuspendMember;
use App\Domain\Chat\ChatFreezer;
use App\Domain\Chat\FlagQueue;
use App\Domain\Chat\MessageRemoval;
use App\Domain\Chat\ReportFreezeRule;
use App\Domain\Safety\AbuseCases;
use App\Enums\AbuseCaseStatus;
use App\Enums\CaseOutcome;
use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagStatus;
use App\Enums\PhotoStatus;
use App\Enums\RejectReason;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Safety\CaseUnavailable;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\Media;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Profile;
use App\Models\Report;
use App\Models\SafetyAction;
use App\Models\User;
use App\Notifications\Safety\ReportReviewed;
use App\Notifications\Safety\SafetyWarning;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Decide an A10 abuse case (PRD A10 outcomes, F07). Needs `safety.reports.act` plus the outcome's
 * own permission (CaseOutcome::extraPermission: safety.ban, members.suspend, members.edit). Only an
 * OPEN case. In one transaction, with the reported member's profile row locked first (as every
 * report path does):
 *  - NO_ACTION: the reports are dismissed; if that takes the member below the automatic
 *    report-freeze rule, the threads it froze open again;
 *  - WARNING: SafetyWarning (in-app live, email, SMS — fixed wording);
 *  - CONTENT_REMOVAL: every message selected in the case's chat reports is removed live, and the
 *    chosen photos of the member (by uuid, theirs only) are rejected;
 *  - HIDE / SUSPEND: the A03 actions (SetMemberProfileHidden / SuspendMember, typed reason);
 *  - BAN: BanMember (blocklist, threads frozen, sessions ended).
 * Then the case closes, every report in it is settled (open A13 REPORT flags on its messages too),
 * one `safety_actions` row and one audit row are written, and each reporter gets "reviewed and
 * closed" (ReportReviewed) — never the outcome. Optional note ≤ 500; HIDE / SUSPEND / BAN need a
 * typed reason (5 – 500).
 */
final class DecideAbuseCase
{
    public function __construct(
        private readonly SuspendMember $suspend,
        private readonly SetMemberProfileHidden $hide,
        private readonly BanMember $ban,
        private readonly MessageRemoval $removal,
        private readonly ChatFreezer $freezer,
        private readonly ReportFreezeRule $freezeRule,
        private readonly FlagQueue $flags,
        private readonly AbuseCases $cases,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $photoUuids  CONTENT_REMOVAL: the member's photos to reject
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws CaseUnavailable decided meanwhile, or the member's account is gone
     * @throws MemberStateConflict the account can't be suspended / banned in its state
     */
    public function handle(AdminUser $admin, AbuseCase $case, CaseOutcome $outcome, ?string $note, array $photoUuids = []): void
    {
        Gate::forUser($admin)->authorize('safety.reports.act');
        if ($outcome->extraPermission() !== null) {
            Gate::forUser($admin)->authorize($outcome->extraPermission());
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);
        Validator::make(['note' => $note, 'photos' => $photoUuids], [
            'note' => array_values(array_filter(['nullable', 'string', 'max:500', $outcome->needsReason() ? 'required' : null, $outcome->needsReason() ? 'min:5' : null])),
            'photos' => ['array', 'max:20'],
            'photos.*' => ['string', 'uuid'],
        ], ['note.required' => __('Type the reason (it is recorded in the audit log).')], ['note' => __('note')])->validate();

        [$warn, $reporters] = DB::transaction(function () use ($admin, $case, $outcome, $note, $photoUuids): array {
            Profile::withTrashed()->whereKey($case->reported_profile_id)->lockForUpdate()->first(['id']);
            $locked = AbuseCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw CaseUnavailable::decided();
            }

            $profile = Profile::withTrashed()->with('user')->find($locked->reported_profile_id);
            $member = $profile?->user;
            if ($outcome !== CaseOutcome::NoAction && $member === null) {
                throw CaseUnavailable::memberGone();
            }

            $details = match ($outcome) {
                CaseOutcome::ContentRemoval => $this->removeContent($locked, $profile, $photoUuids),
                CaseOutcome::Hide => $this->callHide($admin, $member, (string) $note),
                CaseOutcome::Suspend => $this->callSuspend($admin, $member, (string) $note),
                CaseOutcome::Ban => $this->ban->handle($admin, $member, (string) $note, $locked->id),
                default => [],
            };

            $reports = Report::query()->where('abuse_case_id', $locked->id)->lockForUpdate()->get();
            $this->settleReports($admin, $locked, $reports, $outcome);

            $locked->status = AbuseCaseStatus::Closed;
            $locked->open_key = null;
            $locked->outcome = $outcome;
            $locked->outcome_note = $note;
            $locked->decided_by_admin_id = $admin->id;
            $locked->decided_at = now();
            $locked->save();

            $action = new SafetyAction;
            $action->abuse_case_id = $locked->id;
            $action->profile_id = $locked->reported_profile_id;
            $action->admin_id = $admin->id;
            $action->outcome = $outcome;
            $action->note = $note;
            $action->details = $details === [] ? null : $details;
            $action->save();

            $this->audit->record('safety.case.decided', $locked, before: ['status' => AbuseCaseStatus::Open->value, 'priority' => $locked->priority->value],
                after: ['outcome' => $outcome->value, 'reports' => $reports->count(), ...$details],
                reason: $note, actor: $admin, subjectLabel: $profile?->code);

            $this->cases->announceCount();

            $reporterIds = $reports->pluck('reporter_profile_id')->unique()->all();

            return [
                $outcome === CaseOutcome::Warning ? $member : null,
                User::query()->whereIn('id', Profile::withTrashed()->whereKey($reporterIds)->pluck('user_id'))->get(),
            ];
        });

        $warn?->notify(new SafetyWarning);
        foreach ($reporters as $reporter) {
            if ($reporter->isActive()) {
                $reporter->notify(new ReportReviewed);
            }
        }
    }

    /**
     * @param  list<string>  $photoUuids
     * @return array<string, int>
     */
    private function removeContent(AbuseCase $case, ?Profile $profile, array $photoUuids): array
    {
        $messageIds = DB::table('report_messages')->join('reports', 'reports.id', '=', 'report_messages.report_id')
            ->where('reports.abuse_case_id', $case->id)->where('reports.source', ReportSource::Chat->value)
            ->pluck('report_messages.message_id')->unique()->all();

        $messages = 0;
        foreach (Message::query()->whereKey($messageIds)->where('sender_profile_id', $case->reported_profile_id)->lockForUpdate()->get() as $message) {
            $messages += $this->removal->remove($message) ? 1 : 0;
        }

        $photos = 0;
        if ($profile !== null && $photoUuids !== []) {
            $photos = Media::query()->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)
                ->where('collection_name', Profile::PHOTOS)->whereIn('uuid', $photoUuids)
                ->where('moderation_status', '!=', PhotoStatus::Rejected->value)
                ->update(['moderation_status' => PhotoStatus::Rejected->value, 'rejection_reason' => RejectReason::Inappropriate->memberMessage(), 'updated_at' => now()]);
        }

        return ['messages_removed' => $messages, 'photos_rejected' => $photos];
    }

    /** @return array<string, int> */
    private function callHide(AdminUser $admin, ?User $member, string $reason): array
    {
        $this->hide->handle($admin, $member ?? throw CaseUnavailable::memberGone(), true, $reason);

        return [];
    }

    /** @return array<string, int> */
    private function callSuspend(AdminUser $admin, ?User $member, string $reason): array
    {
        $this->suspend->handle($admin, $member ?? throw CaseUnavailable::memberGone(), $reason);

        return [];
    }

    /** @param  Collection<int, Report>  $reports */
    private function settleReports(AdminUser $admin, AbuseCase $case, Collection $reports, CaseOutcome $outcome): void
    {
        $status = $outcome->reportStatus();
        $flagStatus = $outcome === CaseOutcome::NoAction ? MessageFlagStatus::Dismissed : MessageFlagStatus::Actioned;

        foreach ($reports->where('status', ReportStatus::Open) as $report) {
            $report->status = $status;
            $report->decided_at = now();
            $report->save();
        }

        $closed = MessageFlag::query()->whereIn('report_id', $reports->modelKeys())->open()->update([
            'status' => $flagStatus->value,
            'decision' => $outcome === CaseOutcome::NoAction ? MessageFlagDecision::Dismiss->value : null,
            'decision_note' => __('Decided in safety case :code', ['code' => $case->code]),
            'decided_by_admin_id' => $admin->id,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        if ($closed > 0) {
            $this->flags->announce();
        }

        // The automatic freeze after reports is undone as one unit once dismissals take the member below the rule.
        if ($outcome === CaseOutcome::NoAction && ! $this->freezeRule->reached($case->reported_profile_id)) {
            $opened = $this->freezer->unfreezeReportFrozen($case->reported_profile_id);
            if ($opened > 0) {
                $this->audit->record('chat.auto_unfrozen', $case, after: ['threads_opened' => $opened], reason: __('Reports dismissed'),
                    actor: $admin, subjectLabel: Profile::withTrashed()->whereKey($case->reported_profile_id)->value('code'));
            }
        }
    }
}
