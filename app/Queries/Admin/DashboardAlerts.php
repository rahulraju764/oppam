<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\WorkQueueData;
use App\Enums\ImportsPauseReason;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\SystemAlert;
use App\Support\Facades\Settings;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * The dashboard's alert strip (PRD A02), from what the platform can measure today: moderation
 * items waiting past the SLA (`moderation.sla_hours`), the open A12 system alerts (P9.2b2: failed
 * jobs > 10 an hour, missed schedules, webhook signature failures, bounce rate, broadcast lag, Reverb
 * drop — opened by CheckSystemHealth), and a queue holding more jobs than `system.queue_backlog_alert`.
 * A lost live connection is shown by the browser itself.
 */
final class DashboardAlerts
{
    /** The application's queues (CLAUDE.md "Queues"). */
    public const QUEUES = ['default', 'broadcasts', 'notifications', 'mail', 'media', 'matching', 'imports'];

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly PayoutsDue $payoutsDue,
    ) {}

    /**
     * @param  list<WorkQueueData>  $workQueues  the queues this admin may see
     * @param  bool  $system  include the system-health alerts (open A12 alerts, backlog) — for `dashboard.view`
     * @param  bool  $brokers  include bureaus whose imports were auto-paused (§11A B.9 "A07 is alerted") — for `brokers.imports.manage`
     * @param  bool  $bureaus  include bureaus whose adding is paused (R-A07-10) or with open fraud / quality signals (B.12) — for `brokers.view`
     * @param  bool  $payouts  include a broker payout run that is due (§11A B.23) — for `brokers.payout`
     * @return list<array{level: string, text: string}> level = warning | danger
     */
    public function for(array $workQueues, bool $system = true, bool $brokers = false, bool $bureaus = false, bool $payouts = false): array
    {
        $alerts = [];

        if ($payouts && ($month = $this->payoutsDue->month()) !== null) {
            $alerts[] = ['level' => 'warning', 'text' => __('Broker payout run due: no run covers :month yet. Create one in Brokers.', ['month' => $month->format('F Y')])];
        }

        if ($brokers) {
            $paused = Broker::query()->whereNotNull('imports_paused_at')->where('imports_paused_reason', ImportsPauseReason::Auto)->orderBy('imports_paused_at')->limit(5)->pluck('code');
            if ($paused->isNotEmpty()) {
                $alerts[] = ['level' => 'warning', 'text' => __('Bulk imports auto-paused (too many rejections): :codes. Review in Brokers.', ['codes' => $paused->implode(', ')])];
            }

        }

        if ($bureaus) {
            // R-A07-10: create / import rights paused after upheld consent disputes — pending A07's review.
            $paused = Broker::query()->whereNotNull('creation_paused_at');
            if (($count = (clone $paused)->count()) > 0) {
                $alerts[] = ['level' => 'danger', 'text' => __('Adding profiles paused at :n bureau(s): :codes. Review in Brokers.', [
                    'n' => $count, 'codes' => self::codes($paused->orderBy('creation_paused_at')->limit(5)->pluck('code')->all(), $count),
                ])];
            }

            // §11A B.12: bureaus with open fraud / quality signals (P7.8c).
            $flagged = Broker::query()->whereHas('fraudSignals', fn ($q) => $q->whereNull('resolved_at'));
            if (($count = (clone $flagged)->count()) > 0) {
                $alerts[] = ['level' => 'warning', 'text' => __(':n bureau(s) with open fraud or quality signals: :codes. Review in Brokers.', [
                    'n' => $count, 'codes' => self::codes($flagged->orderBy('code')->limit(5)->pluck('code')->all(), $count),
                ])];
            }
        }

        foreach ($workQueues as $queue) {
            if ($queue->breached) {
                $alerts[] = ['level' => 'warning', 'text' => __(':queue: the oldest item has waited :age — past the :hours-hour target.', [
                    'queue' => $queue->label, 'age' => (string) $queue->oldestAge(), 'hours' => Settings::int(SettingKey::ModerationSlaHours),
                ])];
            }
        }

        if (! $system) {
            return $alerts;
        }

        // A12 alerts (P9.2b2): every open system alert — failed jobs > 10 an hour, missed schedules, webhook
        // signature failures, bounces, broadcast lag, a Reverb drop (CheckSystemHealth / A15 thresholds).
        foreach ($this->openSystemAlerts() as $alert) {
            $alerts[] = ['level' => $alert->kind->level(), 'text' => $alert->describe().' '.__('See System health.')];
        }

        $threshold = Settings::int(SettingKey::SystemQueueBacklogAlert);
        foreach ($this->backlog() as $name => $size) {
            if ($size >= $threshold) {
                $alerts[] = ['level' => 'warning', 'text' => __('Queue ":queue" is backed up: :count jobs waiting.', ['queue' => $name, 'count' => number_format($size)])];
            }
        }

        return $alerts;
    }

    /** @return \Illuminate\Support\Collection<int, SystemAlert> open alerts, newest first (none rather than no dashboard if unreadable) */
    private function openSystemAlerts(): \Illuminate\Support\Collection
    {
        return rescue(fn () => SystemAlert::query()->open()->orderByDesc('opened_at')->limit(10)->get(), collect(), report: false);
    }

    /** @return array<string, int> queue name => jobs waiting (empty when the queue backend can't be asked) */
    private function backlog(): array
    {
        try {
            $connection = $this->queues->connection();
            $sizes = [];

            foreach (self::QUEUES as $name) {
                $sizes[$name] = $connection->size($name);
            }

            return $sizes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * "BRK1, BRK2 and 3 more" — the first codes and how many are left out.
     *
     * @param  list<string>  $codes
     */
    private static function codes(array $codes, int $total): string
    {
        $more = $total - count($codes);

        return implode(', ', $codes).($more > 0 ? ' '.__('and :n more', ['n' => $more]) : '');
    }
}
