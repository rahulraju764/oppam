<?php

declare(strict_types=1);

namespace App\Data\Reports;

use App\Enums\ReportMetric;

/** One stored A09 number: a metric, its dimension ('' = the total) and its value for a day (P9.1a). */
final readonly class SnapshotRow
{
    public function __construct(
        public ReportMetric $metric,
        public int $value,
        public string $dimension = '',
    ) {}
}
