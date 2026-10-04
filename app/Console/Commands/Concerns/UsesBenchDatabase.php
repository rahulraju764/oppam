<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Services\Masters\Masters;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P2.1 performance tooling: point THIS process at the `bench` connection (its own database on the
 * same server — owner decision 2026-10-04), so 100k fake profiles never touch local dev data.
 * Local / testing only: refuses anywhere else.
 */
trait UsesBenchDatabase
{
    private function benchAllowed(): bool
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Performance tooling runs only in the local environment.');

            return false;
        }

        return true;
    }

    private function benchDatabase(): string
    {
        $name = (string) config('database.connections.bench.database');

        // Only a plain name with "bench" in it: this database is created and dropped by these commands.
        if (preg_match('/^[a-z0-9_]*bench[a-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('DB_BENCH_DATABASE must be a plain name containing "bench".');
        }

        // Never the app's own database (--fresh drops this one).
        if ($name === (string) config('database.connections.'.config('database.default').'.database')) {
            throw new RuntimeException('DB_BENCH_DATABASE must not be the application database.');
        }

        return $name;
    }

    /**
     * Database AND cache: the cache store was built on the app's connection (CACHE_STORE=database),
     * so without this the bench run would bump the app's masters cache version and write bench
     * master lists into it (P2.1 review). An in-memory store keeps this process fully separate.
     */
    private function useBench(): void
    {
        config(['database.default' => 'bench', 'cache.default' => 'array']);
        DB::purge('bench');
        DB::setDefaultConnection('bench');

        app('cache')->forgetDriver(['database', 'redis', 'file']);
        app()->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
        app()->forgetInstance(Masters::class);
        app()->forgetScopedInstances();
    }
}
