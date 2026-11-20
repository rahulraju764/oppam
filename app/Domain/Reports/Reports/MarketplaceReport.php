<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportStatData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\Calculators\MarketplaceCalculator;
use App\Domain\Reports\ReportRange;
use App\Enums\Gender;
use App\Enums\ReportMetric;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Queries\Reports\LiveMetrics;
use App\Queries\Reports\ReportSeries;
use App\Support\Facades\Settings;
use Generator;

/**
 * A09 Marketplace health (P9.1a): live profiles by gender, district and age band (the level on the
 * last snapshotted day of the period), the photo ratio, and the "no interest" work queue (live
 * profiles nobody has contacted in `reports.zero_interest_days`) — the page shows 20 at a time,
 * the export holds all of them.
 */
final class MarketplaceReport implements ReportDefinition
{
    use FormatsReportNumbers;

    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveMetrics $live,
    ) {}

    public function build(ReportRange $range, bool $compare, ?AdminUser $viewer = null): ReportData
    {
        $levels = [ReportMetric::ActiveProfiles, ReportMetric::ActiveProfilesWithPhoto];
        $t = $this->series->totals($range, $levels);
        $b = $compare ? $this->series->totals($range->previous(), $levels) : null;
        $gender = $this->series->byDimension($range, ReportMetric::ActiveProfiles, 'gender');
        $brides = $gender[Gender::Female->value] ?? 0;
        $grooms = $gender[Gender::Male->value] ?? 0;

        $stats = [
            new ReportStatData(__('Live profiles'), self::n($t['active_profiles']), ReportMetric::ActiveProfiles->definition(),
                $b ? ReportStatData::delta($t['active_profiles'], $b['active_profiles']) : null),
            new ReportStatData(__('Brides'), self::n($brides), __('Live bride profiles.')),
            new ReportStatData(__('Grooms'), self::n($grooms), __('Live groom profiles.')),
            new ReportStatData(__('Brides per 100 grooms'), $grooms > 0 ? self::n((int) round($brides / $grooms * 100)) : '—', __('Gender balance of live profiles.')),
            new ReportStatData(__('With a photo'), self::p(self::pct($t['active_profiles_with_photo'], $t['active_profiles'])), ReportMetric::ActiveProfilesWithPhoto->definition(),
                $b ? ReportStatData::delta(self::pct($t['active_profiles_with_photo'], $t['active_profiles']), self::pct($b['active_profiles_with_photo'], $b['active_profiles']), true) : null),
        ];

        $ages = $this->byPair($range, 'age');
        $bands = array_keys(MarketplaceCalculator::AGE_BANDS);
        $charts = [
            ['id' => 'market-gender', 'title' => __('Live profiles by gender'), 'type' => 'doughnut', 'labels' => [__('Brides'), __('Grooms')],
                'datasets' => [['label' => __('Live profiles'), 'data' => [$brides, $grooms]]]],
            ['id' => 'market-age', 'title' => __('Live profiles by age'), 'type' => 'bar', 'labels' => $bands, 'datasets' => [
                ['label' => __('Brides'), 'data' => array_map(fn (string $b): int => $ages[$b][Gender::Female->value] ?? 0, $bands)],
                ['label' => __('Grooms'), 'data' => array_map(fn (string $b): int => $ages[$b][Gender::Male->value] ?? 0, $bands)],
            ]],
        ];

        return new ReportData($stats, $charts, iterator_to_array($this->tables($range), false), [
            __('Levels are read once a night: a period shows the last night it covers.'),
        ]);
    }

    public function exportTables(ReportRange $range, ?AdminUser $viewer = null): iterable
    {
        yield from $this->tables($range);

        $days = Settings::int(SettingKey::ReportsZeroInterestDays);
        $rows = [];
        foreach ($this->live->zeroInterestAll($days) as $profile) {   // streamed 1,000 at a time
            $rows[] = [$profile->code, $profile->first_name, $profile->gender->label(), $profile->district?->label, $profile->published_at?->toDateString()];
        }
        yield new ReportTableData('zero_interest', __('Live profiles with no interest in :days days', ['days' => $days]),
            [__('Profile'), __('First name'), __('Gender'), __('District'), __('Live since')], $rows);
    }

    public function exportRows(ReportRange $range): int
    {
        $tables = array_sum(array_map(fn (ReportTableData $t): int => count($t->rows), iterator_to_array($this->tables($range), false)));

        return $tables + $this->live->zeroInterestCount(Settings::int(SettingKey::ReportsZeroInterestDays));
    }

    /** @return Generator<int, ReportTableData> */
    private function tables(ReportRange $range): Generator
    {
        $districts = $this->byPair($range, 'district');
        $labels = self::districtLabels(array_map('strval', array_keys($districts)));
        $rows = [];
        foreach ($districts as $district => $g) {
            $f = $g[Gender::Female->value] ?? 0;
            $m = $g[Gender::Male->value] ?? 0;
            $rows[] = [$labels[(string) $district], $f, $m, $m > 0 ? (int) round($f / $m * 100) : '—'];
        }
        usort($rows, fn (array $a, array $b): int => ($b[1] + $b[2]) <=> ($a[1] + $a[2]));
        yield new ReportTableData('gender_by_district', __('Gender balance by district'), [__('District'), __('Brides'), __('Grooms'), __('Brides per 100 grooms')], $rows);

        $ages = $this->byPair($range, 'age');
        $rows = [];
        foreach ([...array_keys(MarketplaceCalculator::AGE_BANDS), 'unknown'] as $band) {
            if (isset($ages[$band])) {
                $rows[] = [$band === 'unknown' ? __('Unknown') : $band, $ages[$band][Gender::Female->value] ?? 0, $ages[$band][Gender::Male->value] ?? 0];
            }
        }
        yield new ReportTableData('gender_by_age', __('Gender balance by age'), [__('Age'), __('Brides'), __('Grooms')], $rows);
    }

    /**
     * "district:12|gender:FEMALE" dimensions → [district => [gender => n]].
     *
     * @return array<string, array<string, int>>
     */
    private function byPair(ReportRange $range, string $prefix): array
    {
        $out = [];
        foreach ($this->series->byDimension($range, ReportMetric::ActiveProfiles, $prefix) as $key => $n) {
            [$value, $gender] = array_pad(explode('|gender:', (string) $key, 2), 2, '');
            $out[$value][$gender] = ($out[$value][$gender] ?? 0) + $n;
        }

        return $out;
    }
}
