<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * A09 capture retention (P9.1a), daily: member activity days after `reports.activity_retention_days`,
 * the anonymous search log after `reports.search_log_retention_days`. The nightly snapshot has
 * already kept the daily totals, so nothing a report shows is lost. Deleted in batches.
 */
final class PruneReportCapture implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BATCH = 5000;

    /** Below the queue's retry_after (960 s). */
    public int $timeout = 900;

    public function handle(): void
    {
        $today = now((string) config('oppam.display_timezone'))->startOfDay();
        $activityBefore = $today->copy()->subDays(Settings::int(SettingKey::ReportsActivityRetentionDays))->toDateString();
        $searchBefore = $today->copy()->subDays(Settings::int(SettingKey::ReportsSearchLogRetentionDays))->toDateString();

        do {
            $deleted = DB::table('member_activity_days')->where('day', '<', $activityBefore)->limit(self::BATCH)->delete();
        } while ($deleted === self::BATCH);

        do {
            $deleted = DB::table('search_logs')->where('day', '<', $searchBefore)->limit(self::BATCH)->delete();
        } while ($deleted === self::BATCH);
    }
}
