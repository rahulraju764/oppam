<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\QueueHealthData;
use App\Data\System\QueueRowData;
use App\Queries\Admin\DashboardAlerts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * A12 "Horizon queues (failed / retry)": per application queue — jobs waiting, failed in 24 h,
 * waiting for a retry, and how long the oldest has waited — plus Horizon's status on Redis.
 *  - database driver (local): ONE grouped query on the jobs table;
 *  - redis: Queue::size per queue, Horizon's wait-time calculator and master supervisors;
 *  - failed_jobs: ONE grouped query (24 h and last hour).
 * Cached 30 s; every source is rescued to "unknown" so the panel always renders.
 */
final class QueueHealth
{
    public const CACHE_KEY = 'system:queue-health';

    public const CACHE_SECONDS = 30;

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly Config $config,
        private readonly Container $container,
        private readonly Cache $cache,
    ) {}

    public function current(): QueueHealthData
    {
        /** @var QueueHealthData */
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): QueueHealthData => $this->measure());
    }

    private function measure(): QueueHealthData
    {
        $connection = (string) $this->config->get('queue.default');
        $driver = (string) $this->config->get("queue.connections.{$connection}.driver");
        [$failedDay, $failedHour] = $this->failed();

        $waiting = $driver === 'database' ? $this->databaseJobs($connection) : $this->sizes();
        $waits = $driver === 'redis' ? $this->horizonWaits() : [];

        $rows = [];
        foreach (DashboardAlerts::QUEUES as $queue) {
            $rows[] = new QueueRowData(
                name: $queue,
                waiting: $waiting[$queue]['waiting'] ?? null,
                failed24h: $failedDay[$queue] ?? 0,
                retrying: $waiting[$queue]['retrying'] ?? null,
                waitSeconds: $waiting[$queue]['wait'] ?? ($waits[$queue] ?? null),
            );
        }

        return new QueueHealthData(
            connection: $connection,
            horizon: $driver === 'redis' ? $this->horizonStatus() : null,
            queues: $rows,
            failedLastHour: array_sum($failedHour),
            failed24h: array_sum($failedDay),
        );
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} queue => failed in 24 h, queue => failed in the last hour */
    private function failed(): array
    {
        try {
            $rows = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->groupBy('queue')
                ->selectRaw('queue, COUNT(*) AS day_count, SUM(CASE WHEN failed_at >= ? THEN 1 ELSE 0 END) AS hour_count', [now()->subHour()])
                ->get();
        } catch (Throwable) {
            return [[], []];
        }

        $day = [];
        $hour = [];
        foreach ($rows as $row) {
            $day[(string) $row->queue] = (int) $row->day_count;
            $hour[(string) $row->queue] = (int) $row->hour_count;
        }

        return [$day, $hour];
    }

    /** @return array<string, array{waiting: int, retrying: int, wait: int|null}> */
    private function databaseJobs(string $connection): array
    {
        try {
            $rows = DB::connection($this->config->get("queue.connections.{$connection}.connection"))
                ->table((string) $this->config->get("queue.connections.{$connection}.table", 'jobs'))
                ->groupBy('queue')
                ->selectRaw('queue, COUNT(*) AS waiting, SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) AS retrying, MIN(CASE WHEN reserved_at IS NULL THEN available_at END) AS oldest')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $now = now()->getTimestamp();
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[(string) $row->queue] = [
                'waiting' => (int) $row->waiting,
                'retrying' => (int) $row->retrying,
                'wait' => $row->oldest === null ? null : max(0, $now - (int) $row->oldest),
            ];
        }

        // A queue with no rows is empty, not unknown.
        foreach (DashboardAlerts::QUEUES as $queue) {
            $jobs[$queue] ??= ['waiting' => 0, 'retrying' => 0, 'wait' => null];
        }

        return $jobs;
    }

    /** @return array<string, array{waiting: int, retrying: null, wait: null}> */
    private function sizes(): array
    {
        $sizes = [];
        foreach (DashboardAlerts::QUEUES as $queue) {
            try {
                $sizes[$queue] = ['waiting' => $this->queues->connection()->size($queue), 'retrying' => null, 'wait' => null];
            } catch (Throwable) {
                // unknown for this queue
            }
        }

        return $sizes;
    }

    /** @return array<string, int> queue => seconds a new job would wait (Horizon's estimate) */
    private function horizonWaits(): array
    {
        try {
            $waits = $this->container->make(WaitTimeCalculator::class)->calculate();
        } catch (Throwable) {
            return [];
        }

        $byQueue = [];
        foreach ($waits as $key => $seconds) {
            $byQueue[(string) preg_replace('/^[^:]+:/', '', (string) $key)] = (int) $seconds;
        }

        return $byQueue;
    }

    /** running | paused | inactive — null when Horizon can't be asked. */
    private function horizonStatus(): ?string
    {
        try {
            $masters = $this->container->make(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return null;
        }

        if ($masters === []) {
            return 'inactive';
        }

        return collect($masters)->contains(fn (object $master): bool => ($master->status ?? null) === 'paused') ? 'paused' : 'running';
    }
}
