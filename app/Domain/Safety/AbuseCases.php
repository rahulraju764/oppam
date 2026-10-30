<?php

declare(strict_types=1);

namespace App\Domain\Safety;

use App\Enums\AbuseCaseStatus;
use App\Enums\CasePriority;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Admin\UrgentCaseOpened;
use App\Jobs\Safety\PageOnCallAdmins;
use App\Models\AbuseCase;
use App\Models\Report;
use App\Services\Safety\CaseCodeGenerator;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;

/**
 * A10 case aggregation and triage (PRD A10, decisions 2026-10-23) — the one place for these rules:
 *  - one OPEN case per reported member; every report (chat or profile) joins it;
 *  - the case takes the most urgent priority of its reports (ReportReason::priority()) and is
 *    never lowered automatically; the SLA due time moves earlier when the priority rises;
 *  - `safety.escalate_reporters` (3) distinct reporters within `safety.escalate_window_days` (7)
 *    — dismissed reports not counting — escalate it to P0;
 *  - the first time a case is P0, on-call admins are paged (PageOnCallAdmins + a live ping).
 * Call inside the caller's transaction with the REPORTED member's profile row locked, so two
 * reports arriving together can't open two cases or miss the escalation.
 */
final class AbuseCases
{
    public function __construct(private readonly CaseCodeGenerator $codes) {}

    public function attach(Report $report): AbuseCase
    {
        $case = $this->openCaseFor($report->reported_profile_id, $report->reason->priority());

        $report->abuse_case_id = $case->id;
        $report->save();

        $case->reports_count = Report::query()->where('abuse_case_id', $case->id)->count();
        $case->reporters_count = Report::query()->where('abuse_case_id', $case->id)->distinct()->count('reporter_profile_id');
        $case->last_reported_at = now();
        $this->raise($case, $report->reason->priority());

        if ($this->escalationReached($report->reported_profile_id)) {
            $this->escalate($case);
        }

        $case->save();
        $this->announce($case);

        return $case;
    }

    /**
     * An A13 moderator escalated a flagged message (MessageFlagDecision::Escalate): the sender's
     * case — opened now if none is open — becomes P0.
     */
    public function escalateProfile(string $profileId): AbuseCase
    {
        $case = $this->openCaseFor($profileId, CasePriority::P0);
        $this->escalate($case);
        $case->save();
        $this->announce($case);

        return $case;
    }

    /**
     * A07 recorded a client's consent dispute on a bureau's managed profile (§11A B.12, R-A07-10):
     * the profile's case — opened now if none is open — takes at least this priority. No reporter.
     */
    public function openForProfile(string $profileId, CasePriority $priority): AbuseCase
    {
        $case = $this->openCaseFor($profileId, $priority);
        $this->raise($case, $priority);
        $case->last_reported_at = now();
        $case->save();
        $this->announce($case);

        return $case;
    }

    /** Distinct members whose reports on this profile still count towards the escalation. */
    public function recentReporters(string $profileId): int
    {
        return Report::query()->where('reported_profile_id', $profileId)
            ->where('status', '!=', ReportStatus::Dismissed->value)
            ->where('created_at', '>=', now()->subDays(Settings::int(SettingKey::SafetyEscalateWindowDays)))
            ->distinct()->count('reporter_profile_id');
    }

    public function escalationReached(string $profileId): bool
    {
        return $this->recentReporters($profileId) >= Settings::int(SettingKey::SafetyEscalateReporters);
    }

    /** The live sidebar badge / queue count, after the surrounding transaction commits. */
    public function announceCount(): void
    {
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(AbuseCase::QUEUE_KEY, AbuseCase::openCount())), report: true);
        });
    }

    private function openCaseFor(string $profileId, CasePriority $priority): AbuseCase
    {
        $case = AbuseCase::query()->where('open_key', $profileId)->lockForUpdate()->first();
        if ($case !== null) {
            return $case;
        }

        $case = new AbuseCase;
        $case->code = $this->codes->next();
        $case->reported_profile_id = $profileId;
        $case->open_key = $profileId;
        $case->status = AbuseCaseStatus::Open;
        $case->priority = $priority;
        $case->sla_due_at = now()->addHours($priority->slaHours());
        $case->save();

        return $case;
    }

    private function raise(AbuseCase $case, CasePriority $priority): void
    {
        if (! $priority->isMoreUrgentThan($case->priority)) {
            return;
        }

        $case->priority = $priority;
        $due = now()->addHours($priority->slaHours());
        if ($due->lt($case->sla_due_at)) {
            $case->sla_due_at = $due;
        }
    }

    private function escalate(AbuseCase $case): void
    {
        $this->raise($case, CasePriority::P0);
        $case->escalated_at ??= now();
    }

    private function announce(AbuseCase $case): void
    {
        $this->announceCount();

        // PRD A10 "Live: new P0 report pings on-call admins via admin.queues + email/SMS" — once per case.
        if ($case->priority === CasePriority::P0 && $case->paged_at === null) {
            $case->paged_at = now();
            $case->save();
            $code = $case->code;
            DB::afterCommit(static function () use ($code): void {
                rescue(static fn () => event(new UrgentCaseOpened($code)), report: true);
                PageOnCallAdmins::dispatch($code);
            });
        }
    }
}
