<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Data\Content\DiffLineData;

/**
 * Compares two versions block by block (A08 legal "diff"): longest common subsequence, so a clause
 * added in the middle shows as one addition, not as every later clause changed.
 */
final class TextDiff
{
    /** Above this many blocks a side, the comparison is skipped rather than slow the admin screen. */
    public const MAX_BLOCKS = 2000;

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<DiffLineData>|null null when either side is too long to compare
     */
    public function compare(array $old, array $new): ?array
    {
        $n = count($old);
        $m = count($new);
        if ($n > self::MAX_BLOCKS || $m > self::MAX_BLOCKS) {
            return null;
        }

        /** @var list<list<int>> $lcs */
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $lines[] = new DiffLineData(DiffLineData::SAME, $old[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $lines[] = new DiffLineData(DiffLineData::REMOVED, $old[$i++]);
            } else {
                $lines[] = new DiffLineData(DiffLineData::ADDED, $new[$j++]);
            }
        }
        while ($i < $n) {
            $lines[] = new DiffLineData(DiffLineData::REMOVED, $old[$i++]);
        }
        while ($j < $m) {
            $lines[] = new DiffLineData(DiffLineData::ADDED, $new[$j++]);
        }

        return $lines;
    }
}
