<?php

declare(strict_types=1);

namespace App\Data\Reports;

/** One A09 headline number (P9.1a): its value and, when comparing, the change against the previous period. */
final readonly class ReportStatData
{
    public function __construct(
        public string $label,
        public string $value,
        public string $definition,
        public ?string $delta = null,
    ) {}

    /** "+12%" / "-3%" / "+4 pts" between two values; null when there is nothing to compare. */
    public static function delta(float|int|null $now, float|int|null $before, bool $points = false): ?string
    {
        if ($now === null || $before === null) {
            return null;
        }
        if ($points) {
            $diff = round((float) $now - (float) $before, 1);

            return ($diff >= 0 ? '+' : '').$diff.' '.__('pts');
        }
        if ((float) $before === 0.0) {
            return (float) $now === 0.0 ? '0%' : null;
        }
        $pct = (int) round(((float) $now - (float) $before) / (float) $before * 100);

        return ($pct >= 0 ? '+' : '').$pct.'%';
    }
}
