<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use Illuminate\Support\Facades\DB;

/**
 * How much a master row is used (A11 "usage_count", owner decision 2026-10-02: computed live, no
 * stored counter). counts() sums the FK references with one grouped query per referencing column;
 * isUsed() also checks the JSON id lists in partner preferences, so a row any profile or
 * preference still points at is never deleted — only deactivated.
 */
final class MasterUsage
{
    /**
     * @param  list<int>  $ids
     * @return array<int, int> id => references
     */
    public function counts(MasterList $list, array $ids): array
    {
        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        foreach ($list->references as [$table, $column]) {
            $rows = DB::table($table)->select($column)->selectRaw('COUNT(*) AS aggregate')
                ->whereIn($column, $ids)->groupBy($column)->get();

            foreach ($rows as $row) {
                $id = (int) ((array) $row)[$column];
                $counts[$id] = ($counts[$id] ?? 0) + (int) $row->aggregate;
            }
        }

        return $counts;
    }

    public function isUsed(MasterList $list, int $id): bool
    {
        foreach ($list->references as [$table, $column]) {
            if (DB::table($table)->where($column, $id)->exists()) {
                return true;
            }
        }

        foreach ($list->jsonReferences as [$table, $column]) {
            // Ids may be stored as numbers or as strings in the JSON list.
            if (DB::table($table)->where(fn ($q) => $q->whereJsonContains($column, $id)->orWhereJsonContains($column, (string) $id))->exists()) {
                return true;
            }
        }

        return false;
    }
}
