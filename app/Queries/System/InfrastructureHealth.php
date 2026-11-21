<?php

declare(strict_types=1);

namespace App\Queries\System;

use App\Data\System\DatabaseHealthData;
use App\Data\System\StorageHealthData;
use App\Data\System\TableSizeData;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A12 "storage, DB stats":
 *  - storage: free / total space where the app writes local files (storage/), and every configured
 *    disk by name and driver (never a bucket, key or path);
 *  - database (MySQL / MariaDB): total size and the 10 largest tables from information_schema,
 *    open connections, uptime and slow queries from SHOW GLOBAL STATUS (readable without extra
 *    privileges). Anything the server won't say is null. Cached 5 minutes.
 */
final class InfrastructureHealth
{
    public const CACHE_KEY = 'system:infrastructure-health';

    public const CACHE_SECONDS = 300;

    public const LARGEST_TABLES = 10;

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    public function storage(): StorageHealthData
    {
        /** @var StorageHealthData */
        return $this->cache->remember(self::CACHE_KEY.':storage', self::CACHE_SECONDS, function (): StorageHealthData {
            $path = storage_path();
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            $disks = [];
            foreach ((array) $this->config->get('filesystems.disks', []) as $name => $disk) {
                $disks[(string) $name] = (string) (((array) $disk)['driver'] ?? '');
            }

            return new StorageHealthData(
                freeBytes: $free === false ? null : (int) $free,
                totalBytes: $total === false ? null : (int) $total,
                disks: $disks,
            );
        });
    }

    public function database(): DatabaseHealthData
    {
        /** @var DatabaseHealthData */
        return $this->cache->remember(self::CACHE_KEY.':database', self::CACHE_SECONDS, fn (): DatabaseHealthData => $this->measureDatabase());
    }

    private function measureDatabase(): DatabaseHealthData
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return new DatabaseHealthData($driver, null, [], null, null, null);
        }

        $size = null;
        $tables = [];
        try {
            $size = (int) DB::scalar('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');

            $tables = array_map(
                fn (object $row): TableSizeData => new TableSizeData((string) $row->name, (int) $row->bytes, (int) $row->row_estimate),
                DB::select('SELECT table_name AS name, (data_length + index_length) AS bytes, COALESCE(table_rows, 0) AS row_estimate
                    FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?
                    ORDER BY bytes DESC LIMIT '.self::LARGEST_TABLES, ['BASE TABLE']),
            );
        } catch (Throwable) {
            // information_schema not readable: sizes stay unknown
        }

        $status = [];
        try {
            foreach (DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Uptime', 'Slow_queries')") as $row) {
                $values = array_values((array) $row);
                $status[(string) $values[0]] = (int) $values[1];
            }
        } catch (Throwable) {
            // status not readable
        }

        return new DatabaseHealthData(
            driver: $driver,
            sizeBytes: $size,
            largestTables: $tables,
            connections: $status['Threads_connected'] ?? null,
            uptimeSeconds: $status['Uptime'] ?? null,
            slowQueries: $status['Slow_queries'] ?? null,
        );
    }
}
