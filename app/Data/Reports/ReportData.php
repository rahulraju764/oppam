<?php

declare(strict_types=1);

namespace App\Data\Reports;

/**
 * One rendered A09 report for a period (P9.1a): headline numbers (with the change against the
 * previous period when comparing), chart definitions for the admin Chart.js wrapper (labels and
 * numbers only), breakdown tables (also what an export contains) and notes ("tracked since").
 */
final readonly class ReportData
{
    /**
     * @param  list<ReportStatData>  $stats
     * @param  list<array{id: string, title: string, type: string, labels: list<string>, datasets: list<array{label: string, data: list<int|float>}>}>  $charts
     * @param  list<ReportTableData>  $tables
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $stats,
        public array $charts,
        public array $tables,
        public array $notes = [],
    ) {}
}
