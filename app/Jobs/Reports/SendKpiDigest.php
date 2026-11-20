<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Domain\Reports\KpiDigest;
use App\Enums\AdminStatus;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Notifications\Admin\WeeklyKpiDigest;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A09 weekly KPI digest (P9.1b; owner 2026-11-13): runs every day at 08:00 IST and sends only on
 * the A15 `reports.digest_day` (default Monday) while `reports.digest_enabled` is on — a changed
 * day needs no deploy. To every active admin holding `reports.view`, at most once per admin per ISO
 * week (a cache claim, as SendPayoutRunReminder). The numbers are built once for everyone.
 */
final class SendKpiDigest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(KpiDigest $digest): void
    {
        $today = CarbonImmutable::now((string) config('oppam.display_timezone'));
        if (! Settings::bool(SettingKey::ReportsDigestEnabled) || $today->dayOfWeekIso !== Settings::int(SettingKey::ReportsDigestDay)) {
            return;
        }

        $week = $digest->lastWeek($today);
        $lines = $digest->lines($week);
        $label = $week->from->format('d M').' – '.$week->to->format('d M Y');
        $isoWeek = $today->format('o-\WW');

        AdminUser::query()->where('status', AdminStatus::Active->value)->get()
            ->filter(fn (AdminUser $admin): bool => $admin->can('reports.view'))
            ->filter(fn (AdminUser $admin): bool => Cache::add('kpi-digest:'.$isoWeek.':'.$admin->id, true, now()->addDays(8)))
            ->each(fn (AdminUser $admin) => $admin->notify(new WeeklyKpiDigest($label, $lines)));
    }
}
