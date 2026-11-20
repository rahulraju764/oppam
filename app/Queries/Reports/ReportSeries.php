<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\ReportRange;
use App\Enums\ReportMetric;
use App\Models\ReportSnapshot;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the stored A09 snapshot numbers for a period (P9.1a). Flows are summed over the days;
 * levels (stock metrics) are read on the last day of the period that has them. Each call is one
 * grouped query, cached 10 minutes per period (snapshots change once a night).
 */
final class ReportSeries
{
    public const CACHE_SECONDS = 600;

    /**
     * Totals (dimension '') for several metrics.
     *
     * @param  list<ReportMetric>  $metrics
     * @return array<string, int> metric value => total (0 when no row)
     */
    public function totals(ReportRange $range, array $metrics): array
    {
        $values = array_map(fn (ReportMetric $m): string => $m->value, $metrics);

        /** @var array<string, int> $sums */
        $sums = $this->cached('totals', $range, $values, fn (): array => ReportSnapshot::query()
            ->whereIn('metric', $values)->where('dimension', '')
            ->whereBetween('day', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('metric, SUM(value) as total')->groupBy('metric')
            ->pluck('total', 'metric')->map(fn ($v): int => (int) $v)->all());

        $out = [];
        foreach ($metrics as $metric) {
            $out[$metric->value] = $metric->kind() === 'stock' ? $this->level($range, $metric) : (int) ($sums[$metric->value] ?? 0);
        }

        return $out;
    }

    /**
     * A metric's total per dimension value with a prefix ("gender", "source", "filter" …); a level
     * is read on the last snapshotted day of the period.
     *
     * @return array<string, int> dimension value (after "prefix:") => total, largest first
     */
    public function byDimension(ReportRange $range, ReportMetric $metric, string $prefix): array
    {
        /** @var array<string, int> $rows */
        $rows = $this->cached('dim:'.$prefix, $range, [$metric->value], function () use ($range, $metric, $prefix): array {
            $query = ReportSnapshot::query()->where('metric', $metric->value)->where('dimension', 'like', $prefix.':%');
            if ($metric->kind() === 'stock') {
                $day = $this->lastDay($range, $metric);
                if ($day === null) {
                    return [];
                }
                $query->where('day', $day);
            } else {
                $query->whereBetween('day', [$range->from->toDateString(), $range->to->toDateString()]);
            }

            return $query->selectRaw('dimension, SUM(value) as total')->groupBy('dimension')
                ->pluck('total', 'dimension')->map(fn ($v): int => (int) $v)->all();
        });

        $out = [];
        foreach ($rows as $dimension => $total) {
            $out[substr((string) $dimension, strlen($prefix) + 1)] = $total;
        }
        arsort($out);

        return $out;
    }

    /**
     * Daily values (dimension '') for a chart: one number per day of the range (0 when missing).
     *
     * @param  list<ReportMetric>  $metrics
     * @return array{labels: list<string>, series: array<string, list<int>>}
     */
    public function daily(ReportRange $range, array $metrics): array
    {
        $values = array_map(fn (ReportMetric $m): string => $m->value, $metrics);

        /** @var list<array{0: string, 1: string, 2: int}> $rows */
        $rows = $this->cached('daily', $range, $values, fn (): array => ReportSnapshot::query()
            ->whereIn('metric', $values)->where('dimension', '')
            ->whereBetween('day', [$range->from->toDateString(), $range->to->toDateString()])
            ->get(['metric', 'day', 'value'])
            ->map(fn (ReportSnapshot $s): array => [$s->metric, $s->day->toDateString(), $s->value])->values()->all());

        $dates = $range->dates();
        $series = array_fill_keys($values, array_fill_keys($dates, 0));
        foreach ($rows as [$metric, $day, $value]) {
            $series[$metric][$day] = $value;
        }

        return [
            'labels' => array_map(fn (string $d): string => substr($d, 5), $dates),
            'series' => array_map(fn (array $byDay): array => array_values($byDay), $series),
        ];
    }

    private function level(ReportRange $range, ReportMetric $metric): int
    {
        $day = $this->lastDay($range, $metric);

        return $day === null ? 0 : (int) ReportSnapshot::query()->where('metric', $metric->value)
            ->where('dimension', '')->where('day', $day)->value('value');
    }

    private function lastDay(ReportRange $range, ReportMetric $metric): ?string
    {
        $day = ReportSnapshot::query()->where('metric', $metric->value)->where('dimension', '')
            ->whereBetween('day', [$range->from->toDateString(), $range->to->toDateString()])->max('day');

        return $day === null ? null : substr((string) $day, 0, 10);
    }

    /**
     * @param  list<string>  $metrics
     * @param  Closure(): array<mixed>  $compute
     * @return array<mixed>
     */
    private function cached(string $kind, ReportRange $range, array $metrics, Closure $compute): array
    {
        // The metric list is hashed: a report asking for many metrics would otherwise outgrow the
        // database cache store's 255-character key column (CACHE_STORE=database locally).
        $key = 'reports:'.$kind.':'.sha1(implode(',', $metrics)).':'.$range->from->toDateString().':'.$range->to->toDateString();

        return (array) Cache::remember($key, self::CACHE_SECONDS, $compute);
    }
}
