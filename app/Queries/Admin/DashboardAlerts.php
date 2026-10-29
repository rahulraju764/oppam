<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\WorkQueueData;
use App\Enums\ImportsPauseReason;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Support\Facades\Settings;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The dashboard's alert strip (PRD A02), from what the platform can measure today: moderation
 * items waiting past the SLA (`moderation.sla_hours`), jobs that failed in the last 24 hours, and
 * a queue holding more jobs than `system.queue_backlog_alert`. A lost live connection is shown by
 * the browser itself. Webhook failures and the error rate arrive with payments (P5) and system
 * health (P9.2).
 */
final class DashboardAlerts
{
    /** The application's queues (CLAUDE.md "Queues"). */
    public const QUEUES = ['default', 'broadcasts', 'notifications', 'mail', 'media', 'matching', 'imports'];

    public function __construct(private readonly QueueFactory $queues) {}

    /**
     * @param  list<WorkQueueData>  $workQueues  the queues this admin may see
     * @param  bool  $system  include the system-health alerts (failed jobs, backlog) — for `dashboard.view`
     * @param  bool  $brokers  include bureaus whose imports were auto-paused (§11A B.9 "A07 is alerted") — for `brokers.imports.manage`
     * @return list<array{level: string, text: string}> level = warning | danger
     */
    public function for(array $workQueues, bool $system = true, bool $brokers = false): array
    {
        $alerts = [];

        if ($brokers) {
            $paused = Broker::query()->whereNotNull('imports_paused_at')->where('imports_paused_reason', ImportsPauseReason::Auto)->orderBy('imports_paused_at')->limit(5)->pluck('code');
            if ($paused->isNotEmpty()) {
                $alerts[] = ['level' => 'warning', 'text' => __('Bulk imports auto-paused (too many rejections): :codes. Review in Brokers.', ['codes' => $paused->implode(', ')])];
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

        $failed = $this->failedJobs();
        if ($failed > 0) {
            $alerts[] = ['level' => 'danger', 'text' => trans_choice(':count background job failed in the last 24 hours.|:count background jobs failed in the last 24 hours.', $failed, ['count' => number_format($failed)])];
        }

        $threshold = Settings::int(SettingKey::SystemQueueBacklogAlert);
        foreach ($this->backlog() as $name => $size) {
            if ($size >= $threshold) {
                $alerts[] = ['level' => 'warning', 'text' => __('Queue ":queue" is backed up: :count jobs waiting.', ['queue' => $name, 'count' => number_format($size)])];
            }
        }

        return $alerts;
    }

    private function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return 0;   // e.g. a deployment that keeps failed jobs elsewhere: no alert rather than no dashboard
        }
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
}
