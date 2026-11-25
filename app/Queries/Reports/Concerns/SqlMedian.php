<?php

declare(strict_types=1);

namespace App\Queries\Reports\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A09 "typical" times as ONE SQL query instead of loading every value into PHP (P9.3c). The value
 * subquery selects an integer column `v` (and `g` for the grouped form); NULLs are left out. The result
 * is the UPPER median — the value at 1-based position floor(n/2)+1 in ascending order — the rule the
 * reports always used (an even count picks the higher of the two middle values), so no number changes.
 * Window functions only (ROW_NUMBER / COUNT OVER): MariaDB 10.2+ and MySQL 8 (MySQL has no MEDIAN()).
 */
final class SqlMedian
{
    public static function of(Builder $values): ?int
    {
        $ranked = DB::query()->fromSub($values, 'vals')->whereNotNull('v')
            ->selectRaw('v, ROW_NUMBER() OVER (ORDER BY v) AS rn, COUNT(*) OVER () AS n');

        $median = DB::query()->fromSub($ranked, 'ranked')->whereRaw('rn = FLOOR(n / 2) + 1')->value('v');

        return $median === null ? null : (int) $median;
    }

    /** @return array<string, int> group `g` => its upper median (groups with no value are absent) */
    public static function byGroup(Builder $values): array
    {
        $ranked = DB::query()->fromSub($values, 'vals')->whereNotNull('v')
            ->selectRaw('g, v, ROW_NUMBER() OVER (PARTITION BY g ORDER BY v) AS rn, COUNT(*) OVER (PARTITION BY g) AS n');

        return DB::query()->fromSub($ranked, 'ranked')->whereRaw('rn = FLOOR(n / 2) + 1')
            ->pluck('v', 'g')->map(fn ($v): int => (int) $v)->all();
    }
}
