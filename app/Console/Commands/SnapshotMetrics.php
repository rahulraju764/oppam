<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\Metrics\TakeMetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Writes the admin dashboard's KPI snapshot (PRD A02). Scheduled hourly for today and once just
 * after midnight IST with --yesterday to close the previous day (routes/console.php).
 */
final class SnapshotMetrics extends Command
{
    protected $signature = 'metrics:snapshot {--yesterday : Close the previous IST day instead of updating today}';

    protected $description = 'Store the admin dashboard KPI snapshot for today (or yesterday)';

    public function handle(TakeMetricSnapshot $snapshot): int
    {
        $day = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();
        $day = $this->option('yesterday') ? $day->subDay() : $day;

        $values = $snapshot->handle($day);

        $this->info(sprintf('Stored %d metrics for %s.', count($values), $day->toDateString()));

        return self::SUCCESS;
    }
}
