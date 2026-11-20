<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\Calculators\EngagementCalculator;
use App\Domain\Reports\Calculators\GrowthCalculator;
use App\Domain\Reports\Calculators\MarketplaceCalculator;
use App\Domain\Reports\Calculators\RevenueCalculator;
use App\Domain\Reports\Calculators\TrustOpsCalculator;
use App\Domain\Reports\DayWindow;
use App\Enums\ReportMetric;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Store every A09 snapshot number for one IST day (P9.1a; PRD A09 "snapshot-backed"). A run
 * REPLACES the day's rows of each metric it computes, in one transaction (dimensions that
 * disappeared go too), so reruns are idempotent. What is computed for a day:
 *  - flows from the app's own timestamps: always (so any past day can be backfilled);
 *  - captured metrics (activity days, search log): only from the first day their table still holds
 *    — earlier source rows never existed or were pruned, and recomputing would zero stored history;
 *  - levels (marketplace health): only for today or yesterday — a past level can't be rebuilt.
 */
final class TakeReportSnapshot
{
    public function __construct(
        private readonly GrowthCalculator $growth,
        private readonly EngagementCalculator $engagement,
        private readonly MarketplaceCalculator $marketplace,
        private readonly RevenueCalculator $revenue,
        private readonly TrustOpsCalculator $trust,
    ) {}

    /** @return int rows stored */
    public function handle(CarbonImmutable $day): int
    {
        $window = DayWindow::of($day);
        $today = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();
        $withLevels = $window->date >= $today->subDay()->toDateString();

        $replaced = array_values(array_filter(ReportMetric::cases(), fn (ReportMetric $m): bool => $this->computes($m, $window, $withLevels)));
        $keep = array_map(fn (ReportMetric $m): string => $m->value, $replaced);

        $rows = [...$this->growth->day($window), ...$this->engagement->day($window), ...$this->revenue->day($window), ...$this->trust->day($window)];
        if ($withLevels) {
            array_push($rows, ...$this->marketplace->day($window));
        }
        $rows = array_values(array_filter($rows, fn (SnapshotRow $r): bool => in_array($r->metric->value, $keep, true)));

        $now = now();
        DB::transaction(function () use ($window, $keep, $rows, $now): void {
            ReportSnapshot::query()->where('day', $window->date)->whereIn('metric', $keep)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ReportSnapshot::query()->insert(array_map(fn (SnapshotRow $r): array => [
                    'metric' => $r->metric->value,
                    'day' => $window->date,
                    'dimension' => substr($r->dimension, 0, 60),
                    'value' => $r->value,
                    'computed_at' => $now,
                ], $chunk));
            }
        });

        return count($rows);
    }

    private function computes(ReportMetric $metric, DayWindow $window, bool $withLevels): bool
    {
        if ($metric->kind() === 'stock') {
            return $withLevels;
        }

        // Not final yet (churn: "not renewed within 30 days") — leave the day without it.
        if ($metric->settlesAfterDays() > 0) {
            $settled = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay()->subDays($metric->settlesAfterDays() + 1);
            if ($window->date > $settled->toDateString()) {
                return false;
            }
        }

        $table = $metric->captureTable();
        if ($table === null) {
            return true;
        }
        $since = ReportMetric::capturedSince($table);

        return $since !== null && $window->date >= $since->toDateString();
    }
}
