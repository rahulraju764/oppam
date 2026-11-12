<?php

declare(strict_types=1);

namespace App\Data\Reports;

/** One A09 breakdown table (P9.1a): shown on the report and exported as CSV / Excel. */
final readonly class ReportTableData
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $headers,
        public array $rows,
    ) {}
}
