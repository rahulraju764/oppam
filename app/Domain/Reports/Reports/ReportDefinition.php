<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;

/** One A09 report (P9.1a): what it shows for a period, and what an export of it contains. */
interface ReportDefinition
{
    /** $compare: also read the previous period of the same length and show the change. */
    public function build(ReportRange $range, bool $compare): ReportData;

    /**
     * Everything the export holds: the report's breakdown tables (and long lists the page only
     * shows a page of).
     *
     * @return iterable<ReportTableData>
     */
    public function exportTables(ReportRange $range): iterable;

    /** How many data rows the export would hold (decides: download now, or build it in the background). */
    public function exportRows(ReportRange $range): int;
}
