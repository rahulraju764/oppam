<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportStatData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Enums\Gender;
use App\Enums\ReportMetric;
use App\Enums\SignupSource;
use App\Queries\Reports\LiveMetrics;
use App\Queries\Reports\ReportSeries;
use Generator;

/**
 * A09 Growth (P9.1a): signups by source / gender / district, profiles approved, the 6-step
 * activation funnel of the period's registrations, weekly cohorts (share active in the weeks after
 * signing up). Source and cohorts rely on capture that started when P9.1a went live (trackedSince).
 */
final class GrowthReport implements ReportDefinition
{
    use FormatsReportNumbers;

    public const TOP_DISTRICTS = 15;

    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveMetrics $live,
    ) {}

    public function build(ReportRange $range, bool $compare): ReportData
    {
        $now = $this->series->totals($range, [ReportMetric::Signups, ReportMetric::ProfilesApproved]);
        $before = $compare ? $this->series->totals($range->previous(), [ReportMetric::Signups, ReportMetric::ProfilesApproved]) : null;
        $bySource = $this->series->byDimension($range, ReportMetric::Signups, 'source');
        $broker = $bySource[SignupSource::Broker->value] ?? 0;
        $daily = $this->series->daily($range, [ReportMetric::Signups, ReportMetric::ProfilesApproved]);

        $stats = [
            new ReportStatData(__('Signups'), self::n($now['signups']), ReportMetric::Signups->definition(),
                $before ? ReportStatData::delta($now['signups'], $before['signups']) : null),
            new ReportStatData(__('Profiles approved'), self::n($now['profiles_approved']), ReportMetric::ProfilesApproved->definition(),
                $before ? ReportStatData::delta($now['profiles_approved'], $before['profiles_approved']) : null),
            new ReportStatData(__('Through a bureau'), self::p(self::pct($broker, $now['signups'])), __('Share of signups that came with a resolved bureau referral code.')),
        ];

        $charts = [
            ['id' => 'growth-daily', 'title' => __('Signups and approvals per day'), 'type' => 'line', 'labels' => $daily['labels'], 'datasets' => [
                ['label' => __('Signups'), 'data' => $daily['series']['signups']],
                ['label' => __('Profiles approved'), 'data' => $daily['series']['profiles_approved']],
            ]],
            ['id' => 'growth-source', 'title' => __('Signups by source'), 'type' => 'doughnut',
                'labels' => array_map(fn (string $s): string => SignupSource::tryFrom($s)?->label() ?? $s, array_keys($bySource)),
                'datasets' => [['label' => __('Signups'), 'data' => array_values($bySource)]]],
        ];

        return new ReportData($stats, $charts, iterator_to_array($this->tables($range, $now['signups']), false), [
            __('Signup source and cohorts are tracked since :date; earlier members count as direct (or bureau when a code was used).', ['date' => self::trackedSince()]),
            __('The funnel follows the members who registered in this period, as they are today.'),
        ]);
    }

    public function exportTables(ReportRange $range): iterable
    {
        return $this->tables($range, $this->series->totals($range, [ReportMetric::Signups])['signups']);
    }

    public function exportRows(ReportRange $range): int
    {
        return array_sum(array_map(fn (ReportTableData $t): int => count($t->rows), iterator_to_array($this->exportTables($range), false)));
    }

    /** @return Generator<int, ReportTableData> */
    private function tables(ReportRange $range, int $signups): Generator
    {
        $share = fn (int $n): string => self::p(self::pct($n, $signups));

        $rows = [];
        foreach ($this->series->byDimension($range, ReportMetric::Signups, 'source') as $source => $n) {
            $rows[] = [SignupSource::tryFrom($source)?->label() ?? $source, $n, $share($n)];
        }
        yield new ReportTableData('signups_by_source', __('Signups by source'), [__('Source'), __('Signups'), __('Share')], $rows);

        $rows = [];
        foreach ($this->series->byDimension($range, ReportMetric::Signups, 'gender') as $gender => $n) {
            $rows[] = [Gender::tryFrom($gender)?->label() ?? __('Not set yet'), $n, $share($n)];
        }
        yield new ReportTableData('signups_by_gender', __('Signups by gender'), [__('Gender'), __('Signups'), __('Share')], $rows);

        $byDistrict = $this->series->byDimension($range, ReportMetric::Signups, 'district');
        $labels = self::districtLabels(array_map('strval', array_keys($byDistrict)));
        $rows = [];
        $other = 0;
        foreach ($byDistrict as $district => $n) {
            if (count($rows) < self::TOP_DISTRICTS) {
                $rows[] = [$labels[(string) $district], $n, $share($n)];
            } else {
                $other += $n;
            }
        }
        if ($other > 0) {
            $rows[] = [__('Other districts'), $other, $share($other)];
        }
        yield new ReportTableData('signups_by_district', __('Signups by district'), [__('District'), __('Signups'), __('Share')], $rows);

        $funnel = $this->live->funnel($range);
        $registered = max(1, (int) reset($funnel));
        $rows = [];
        foreach ($funnel as $step => $n) {
            $rows[] = [$step, $n, self::p(self::pct($n, $registered))];
        }
        yield new ReportTableData('activation_funnel', __('Activation funnel'), [__('Step'), __('Members'), __('Of registered')], $rows);

        $rows = [];
        foreach ($this->live->cohorts($range) as $cohort) {
            $rows[] = [$cohort['week'], $cohort['members'], ...array_map(fn (?int $p): string => $p === null ? '—' : $p.'%', $cohort['weeks'])];
        }
        yield new ReportTableData('cohorts', __('Weekly cohorts — share active in the weeks after signing up'),
            [__('Signup week'), __('Members'), ...array_map(fn (int $w): string => __('Week :n', ['n' => $w]), range(1, LiveMetrics::COHORT_WEEKS))], $rows);
    }
}
