<?php

declare(strict_types=1);

namespace App\Domain\Reports\Calculators;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\DayWindow;
use App\Enums\ReportMetric;
use App\Enums\SettingKey;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;

/**
 * A09 trust & ops numbers for one IST day (P9.1b), each a count on the day it happened:
 *  - ID verifications and moderation items decided, and those decided within their SLA
 *    (A15 `verification.aging_breach_hours` / `moderation.sla_hours`, counted from submission) —
 *    moderation also by item type and outcome;
 *  - reports made (by their case's priority), abuse cases decided and those decided after `sla_due_at`;
 *  - support tickets opened, first-answered (and within `first_response_due_at`) and resolved.
 * The SLA hours used are today's settings (a changed SLA re-judges a backfilled day — documented).
 */
final class TrustOpsCalculator
{
    /** @return list<SnapshotRow> */
    public function day(DayWindow $day): array
    {
        $verificationHours = Settings::int(SettingKey::VerificationAgingBreachHours);
        $moderationHours = Settings::int(SettingKey::ModerationSlaHours);

        $verified = DB::table('verification_requests')->whereNotNull('decided_at')
            ->where('decided_at', '>=', $day->from)->where('decided_at', '<', $day->to);
        $rows = [
            new SnapshotRow(ReportMetric::VerificationDecided, (clone $verified)->count()),
            new SnapshotRow(ReportMetric::VerificationWithinSla, (clone $verified)
                ->whereRaw('decided_at <= DATE_ADD(submitted_at, INTERVAL ? HOUR)', [$verificationHours])->count()),
        ];

        $moderated = DB::table('moderation_items')->whereNotNull('decided_at')
            ->where('decided_at', '>=', $day->from)->where('decided_at', '<', $day->to)
            ->selectRaw('type, status, COUNT(*) as n, SUM(CASE WHEN decided_at <= DATE_ADD(submitted_at, INTERVAL ? HOUR) THEN 1 ELSE 0 END) as within', [$moderationHours])
            ->groupBy('type', 'status')
            ->get();
        $rows[] = new SnapshotRow(ReportMetric::ModerationDecided, (int) $moderated->sum('n'));
        $rows[] = new SnapshotRow(ReportMetric::ModerationWithinSla, (int) $moderated->sum('within'));
        foreach ($moderated->groupBy('type') as $type => $group) {
            $rows[] = new SnapshotRow(ReportMetric::ModerationDecided, (int) $group->sum('n'), 'type:'.$type);
            $rows[] = new SnapshotRow(ReportMetric::ModerationWithinSla, (int) $group->sum('within'), 'type:'.$type);
        }
        foreach ($moderated->groupBy('status') as $status => $group) {
            $rows[] = new SnapshotRow(ReportMetric::ModerationDecided, (int) $group->sum('n'), 'outcome:'.$status);
        }

        $reports = DB::table('reports')
            ->leftJoin('abuse_cases', 'abuse_cases.id', '=', 'reports.abuse_case_id')
            ->where('reports.created_at', '>=', $day->from)->where('reports.created_at', '<', $day->to)
            ->selectRaw('abuse_cases.priority as priority, COUNT(*) as n')->groupBy('abuse_cases.priority')->get();
        $rows[] = new SnapshotRow(ReportMetric::ReportsReceived, (int) $reports->sum('n'));
        foreach ($reports as $r) {
            $rows[] = new SnapshotRow(ReportMetric::ReportsReceived, (int) $r->n, 'priority:'.($r->priority ?? 'none'));
        }

        $cases = DB::table('abuse_cases')->whereNotNull('decided_at')
            ->where('decided_at', '>=', $day->from)->where('decided_at', '<', $day->to);
        $rows[] = new SnapshotRow(ReportMetric::CasesDecided, (clone $cases)->count());
        $rows[] = new SnapshotRow(ReportMetric::CasesBreached, (clone $cases)->whereNotNull('sla_due_at')->whereColumn('decided_at', '>', 'sla_due_at')->count());

        $tickets = fn (string $column) => DB::table('support_tickets')->where($column, '>=', $day->from)->where($column, '<', $day->to);
        $rows[] = new SnapshotRow(ReportMetric::TicketsOpened, $tickets('created_at')->count());
        $rows[] = new SnapshotRow(ReportMetric::TicketsFirstResponded, $tickets('first_responded_at')->count());
        $rows[] = new SnapshotRow(ReportMetric::TicketsFirstResponseInSla, $tickets('first_responded_at')
            ->where(fn ($q) => $q->whereNull('first_response_due_at')->orWhereColumn('first_responded_at', '<=', 'first_response_due_at'))->count());
        $rows[] = new SnapshotRow(ReportMetric::TicketsResolved, $tickets('resolved_at')->count());

        return $rows;
    }
}
