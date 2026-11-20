<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reports\TakeReportSnapshot;
use App\Enums\ReportMetric;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * A09 nightly report snapshot (P9.1a). Default: yesterday (IST), scheduled 00:30 IST. --day= one
 * day; --from= --to= a backfill of the days in between (flows only — levels can't be rebuilt),
 * at most MAX_DAYS at a time.
 */
final class SnapshotReports extends Command
{
    public const MAX_DAYS = 400;

    protected $signature = 'reports:snapshot {--day= : One IST day (Y-m-d)} {--from= : Backfill start (Y-m-d)} {--to= : Backfill end (Y-m-d)}';

    protected $description = 'Store the A09 report snapshot for yesterday (or the given days)';

    public function handle(TakeReportSnapshot $snapshot): int
    {
        $tz = (string) config('oppam.display_timezone');

        try {
            [$from, $to] = match (true) {
                $this->option('from') !== null || $this->option('to') !== null => [
                    CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), $tz),
                    CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('to'), $tz),
                ],
                $this->option('day') !== null => array_fill(0, 2, CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('day'), $tz)),
                default => array_fill(0, 2, CarbonImmutable::now($tz)->startOfDay()->subDay()),
            };
        } catch (Throwable) {
            $from = $to = false;
        }

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $from->greaterThan($to)
            || $to->greaterThan(CarbonImmutable::now($tz)) || $from->diffInDays($to) >= self::MAX_DAYS) {
            $this->error(sprintf('Give valid IST dates (Y-m-d), from ≤ to, not in the future, at most %d days.', self::MAX_DAYS));

            return self::FAILURE;
        }

        $rows = 0;
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $rows += $snapshot->handle($day);
        }
        $this->info(sprintf('Stored %d report rows for %s to %s.', $rows, $from->toDateString(), $to->toDateString()));

        // The nightly run also settles the day whose churn just became final (P9.1b: 30 days on).
        if ($this->option('day') === null && $this->option('from') === null && $this->option('to') === null) {
            $settled = CarbonImmutable::now($tz)->startOfDay()->subDays(ReportMetric::CHURN_DAYS + 1);
            $this->info(sprintf('Settled %s (%d rows).', $settled->toDateString(), $snapshot->handle($settled)));
        }

        return self::SUCCESS;
    }
}
