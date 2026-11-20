<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Models\AdminUser;

/**
 * One A09 report (P9.1a / P9.1b): what it shows for a period, and what an export of it contains.
 * $viewer = the admin looking at / exporting it — a report may hold rows only some admins may see
 * (Trust & ops: per-moderator rows need `reports.staff`); null shows only what everyone may see.
 */
interface ReportDefinition
{
    /** $compare: also read the previous period of the same length and show the change. */
    public function build(ReportRange $range, bool $compare, ?AdminUser $viewer = null): ReportData;

    /**
     * Everything the export holds: the report's breakdown tables (and long lists the page only
     * shows a page of).
     *
     * @return iterable<ReportTableData>
     */
    public function exportTables(ReportRange $range, ?AdminUser $viewer = null): iterable;

    /** How many data rows the export would hold (decides: download now, or build it in the background). */
    public function exportRows(ReportRange $range): int;
}
