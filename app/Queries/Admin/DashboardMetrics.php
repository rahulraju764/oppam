<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Enums\Metric;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the admin dashboard shows from `metric_snapshots` (PRD A02) — never a live aggregate:
 * each KPI's latest value with its change against 30 days earlier, and daily series for the
 * charts. One query loads the whole window; "as of" is the newest snapshot's time.
 */
final class DashboardMetrics
{
    public const WINDOW_DAYS = 30;

    /** Tiles of the KPI row, in order. "Online now" is not a snapshot: the dashboard adds it. */
    public const KPIS = [
        Metric::TotalMembers, Metric::NewMembers, Metric::ActiveMembers7d, Metric::PaidMembers,
        Metric::Messages, Metric::Likes,
    ];

    /** @var Collection<string, Collection<string, MetricSnapshot>>|null metric => day => snapshot */
    private ?Collection $window = null;

    /**
     * @return list<array{metric: Metric, value: int|null, delta: int|null, deltaLabel: string|null}>
     *                                                                                                value null = no snapshot yet; delta = change in % against 30 days earlier (finished days for daily counts)
     */
    public function kpis(): array
    {
        $today = $this->today();

        return array_map(function (Metric $metric) use ($today): array {
            $days = $this->window()->get($metric->value, collect());
            $current = $days->get($today->toDateString()) ?? $days->sortKeysDesc()->first();

            // The change is day against day. A level (total members) compares now with 30 days
            // earlier; a day's count (new today) is still growing, so it would always look like a
            // fall against a finished day — its change compares yesterday with the day 30 before it.
            $base = $metric->isStock() ? $today : $today->subDay();
            $delta = $this->delta(
                $days->get($base->toDateString())?->value,
                $days->get($base->subDays(self::WINDOW_DAYS)->toDateString())?->value,
            );

            return [
                'metric' => $metric,
                'value' => $current?->value,
                'delta' => $delta,
                'deltaLabel' => $delta === null ? null : ($delta > 0 ? '+' : '').$delta.'%',
            ];
        }, self::KPIS);
    }

    /**
     * One value per day for the last WINDOW_DAYS days (oldest first); a day without a snapshot is 0.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function series(Metric $metric): array
    {
        $days = $this->window()->get($metric->value, collect());
        $labels = [];
        $values = [];

        for ($i = self::WINDOW_DAYS - 1; $i >= 0; $i--) {
            $day = $this->today()->subDays($i);
            $labels[] = $day->format('j M');
            $values[] = $days->get($day->toDateString())->value ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** The latest stored value of a metric (for the gender split), or null. */
    public function latest(Metric $metric): ?int
    {
        return $this->window()->get($metric->value, collect())->sortKeysDesc()->first()?->value;
    }

    /** When the newest snapshot was computed — the dashboard's "as of". */
    public function asOf(): ?CarbonImmutable
    {
        return $this->window()->flatten(1)->max(fn (MetricSnapshot $s): CarbonImmutable => $s->computed_at);
    }

    private function delta(?int $current, ?int $earlier): ?int
    {
        if ($current === null || $earlier === null || $earlier === 0) {
            return null;
        }

        return (int) round(($current - $earlier) / $earlier * 100);
    }

    /** @return Collection<string, Collection<string, MetricSnapshot>> */
    private function window(): Collection
    {
        return $this->window ??= MetricSnapshot::query()
            // Only metrics this code knows: a key left over from a renamed metric must not break the page.
            ->whereIn('metric', array_map(fn (Metric $m): string => $m->value, Metric::cases()))
            ->where('day', '>=', $this->today()->subDays(self::WINDOW_DAYS + 1)->toDateString())
            ->get()
            ->groupBy(fn (MetricSnapshot $s): string => $s->metric->value)
            ->map(fn (Collection $rows): Collection => $rows->keyBy(fn (MetricSnapshot $s): string => $s->day->toDateString()));
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();
    }
}
