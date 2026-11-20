<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportStatData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Enums\CasePriority;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ReportMetric;
use App\Models\AdminUser;
use App\Queries\Admin\WorkQueues;
use App\Queries\Reports\LiveOps;
use App\Queries\Reports\ReportSeries;
use Generator;
use Illuminate\Support\Facades\Gate;

/**
 * A09 Trust & operations (P9.1b): share of ID verifications, moderation items and first ticket
 * answers within their SLA, typical times, reports by priority, abuse cases past their SLA, the
 * backlog right now (A02 work queues the viewer may open), and moderator quality. Per-moderator
 * rows (admin names) only for an admin holding `reports.staff` (owner 2026-11-13); everyone else
 * — and every export by them — gets the team totals only.
 */
final class TrustReport implements ReportDefinition
{
    use FormatsReportNumbers;

    private const FLOWS = [
        ReportMetric::VerificationDecided, ReportMetric::VerificationWithinSla, ReportMetric::ModerationDecided,
        ReportMetric::ModerationWithinSla, ReportMetric::ReportsReceived, ReportMetric::CasesDecided, ReportMetric::CasesBreached,
        ReportMetric::TicketsOpened, ReportMetric::TicketsFirstResponded, ReportMetric::TicketsFirstResponseInSla, ReportMetric::TicketsResolved,
    ];

    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveOps $live,
        private readonly WorkQueues $queues,
    ) {}

    public function build(ReportRange $range, bool $compare, ?AdminUser $viewer = null): ReportData
    {
        $t = $this->series->totals($range, self::FLOWS);
        $b = $compare ? $this->series->totals($range->previous(), self::FLOWS) : null;
        $m = $this->live->medians($range);
        $rate = fn (array $x, string $part, string $whole): ?float => self::pct($x[$part], $x[$whole]);
        $sla = fn (string $part, string $whole): ?string => $b ? ReportStatData::delta($rate($t, $part, $whole), $rate($b, $part, $whole), true) : null;

        $stats = [
            new ReportStatData(__('ID checks within SLA'), self::p($rate($t, 'verification_within_sla', 'verification_decided')), ReportMetric::VerificationWithinSla->definition(), $sla('verification_within_sla', 'verification_decided')),
            new ReportStatData(__('Moderation within SLA'), self::p($rate($t, 'moderation_within_sla', 'moderation_decided')), ReportMetric::ModerationWithinSla->definition(), $sla('moderation_within_sla', 'moderation_decided')),
            new ReportStatData(__('Tickets answered within SLA'), self::p($rate($t, 'tickets_first_response_in_sla', 'tickets_first_responded')), ReportMetric::TicketsFirstResponseInSla->definition(), $sla('tickets_first_response_in_sla', 'tickets_first_responded')),
            new ReportStatData(__('Abuse cases past SLA'), self::n($t['cases_breached']).' / '.self::n($t['cases_decided']), ReportMetric::CasesBreached->definition()),
            new ReportStatData(__('Typical moderation time'), $m['moderation_minutes'] === null ? '—' : self::n($m['moderation_minutes']).' '.__('min'), __('Median minutes from submission to decision.')),
            new ReportStatData(__('Typical ID-check time'), $m['verification_hours'] === null ? '—' : self::n($m['verification_hours']).' '.__('h'), __('Median hours from submission to decision.')),
            new ReportStatData(__('Typical first answer'), $m['ticket_hours'] === null ? '—' : self::n($m['ticket_hours']).' '.__('h'), __('Median hours from a ticket to its first answer.')),
            new ReportStatData(__('Reports received'), self::n($t['reports_received']), ReportMetric::ReportsReceived->definition(), $b ? ReportStatData::delta($t['reports_received'], $b['reports_received']) : null),
        ];

        $daily = $this->series->daily($range, [ReportMetric::ModerationDecided, ReportMetric::VerificationDecided, ReportMetric::TicketsResolved]);
        $charts = [
            ['id' => 'trust-daily', 'title' => __('Decisions per day'), 'type' => 'bar', 'labels' => $daily['labels'], 'datasets' => [
                ['label' => __('Moderation'), 'data' => $daily['series']['moderation_decided']],
                ['label' => __('ID checks'), 'data' => $daily['series']['verification_decided']],
                ['label' => __('Tickets resolved'), 'data' => $daily['series']['tickets_resolved']],
            ]],
        ];

        $notes = [__('SLA shares use today\'s SLA settings. The backlog is the queues right now.')];
        if (! $this->seesModerators($viewer)) {
            $notes[] = __('Per-moderator numbers are shown to admins with the "reports.staff" permission.');
        }

        return new ReportData($stats, $charts, [...iterator_to_array($this->tables($range, $viewer), false), $this->backlog($viewer)], $notes);
    }

    public function exportTables(ReportRange $range, ?AdminUser $viewer = null): iterable
    {
        return $this->tables($range, $viewer);
    }

    public function exportRows(ReportRange $range): int
    {
        return array_sum(array_map(fn (ReportTableData $t): int => count($t->rows), iterator_to_array($this->tables($range, null), false)));
    }

    /** @return Generator<int, ReportTableData> */
    private function tables(ReportRange $range, ?AdminUser $viewer): Generator
    {
        $decided = $this->series->byDimension($range, ReportMetric::ModerationDecided, 'type');
        $within = $this->series->byDimension($range, ReportMetric::ModerationWithinSla, 'type');
        $rows = [];
        foreach ($decided as $type => $n) {
            $rows[] = [ModerationItemType::tryFrom((string) $type)?->label() ?? (string) $type, $n, $within[$type] ?? 0, self::p(self::pct($within[$type] ?? 0, $n))];
        }
        yield new ReportTableData('moderation_by_type', __('Moderation by item type'), [__('Item'), __('Decided'), __('Within SLA'), __('Share')], $rows);

        $rows = [];
        foreach ($this->series->byDimension($range, ReportMetric::ModerationDecided, 'outcome') as $status => $n) {
            $rows[] = [ModerationStatus::tryFrom((string) $status)?->label() ?? (string) $status, $n];
        }
        yield new ReportTableData('moderation_outcomes', __('Moderation outcomes'), [__('Outcome'), __('Decisions')], $rows);

        $rows = [];
        foreach ($this->series->byDimension($range, ReportMetric::ReportsReceived, 'priority') as $priority => $n) {
            $rows[] = [CasePriority::tryFrom((string) $priority)?->label() ?? __('Not in a case yet'), $n];
        }
        yield new ReportTableData('reports_by_priority', __('Reports by case priority'), [__('Priority'), __('Reports')], $rows);

        $t = $this->series->totals($range, [ReportMetric::TicketsOpened, ReportMetric::TicketsFirstResponded, ReportMetric::TicketsFirstResponseInSla, ReportMetric::TicketsResolved]);
        yield new ReportTableData('tickets', __('Support tickets'), [__('Opened'), __('Answered'), __('Answered within SLA'), __('Resolved')],
            [[$t['tickets_opened'], $t['tickets_first_responded'], $t['tickets_first_response_in_sla'], $t['tickets_resolved']]]);

        $moderators = $this->live->moderators($range);
        if ($this->seesModerators($viewer)) {
            $rows = array_map(fn (array $r): array => [$r['name'], $r['decided'], $r['approved'], $r['rejected'], $r['changes'],
                $r['median_minutes'] ?? '—', self::p(self::pct($r['within_sla'], $r['decided']))], $moderators);
            yield new ReportTableData('moderator_quality', __('Moderator quality'),
                [__('Moderator'), __('Decisions'), __('Approved'), __('Rejected'), __('Changes asked'), __('Typical minutes'), __('Within SLA')], $rows);
        } else {
            $sum = fn (string $k): int => array_sum(array_column($moderators, $k));
            yield new ReportTableData('moderator_quality', __('Moderation team'),
                [__('Moderators'), __('Decisions'), __('Approved'), __('Rejected'), __('Changes asked'), __('Within SLA')],
                $moderators === [] ? [] : [[count($moderators), $sum('decided'), $sum('approved'), $sum('rejected'), $sum('changes'), self::p(self::pct($sum('within_sla'), $sum('decided')))]]);
        }
    }

    private function backlog(?AdminUser $viewer): ReportTableData
    {
        $rows = [];
        foreach ($this->queues->for($viewer) as $queue) {
            $rows[] = [$queue->label, $queue->count, $queue->oldestAge() ?? '—', $queue->breached ? __('Yes') : __('No')];
        }

        return new ReportTableData('backlog', __('Backlog right now'), [__('Queue'), __('Waiting'), __('Oldest'), __('Past SLA')], $rows);
    }

    private function seesModerators(?AdminUser $viewer): bool
    {
        return $viewer !== null && Gate::forUser($viewer)->allows('reports.staff');
    }
}
