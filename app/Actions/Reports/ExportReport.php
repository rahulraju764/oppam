<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\ReportRegistry;
use App\Enums\AdminExportStatus;
use App\Enums\ExportFormat;
use App\Enums\ReportKey;
use App\Enums\SettingKey;
use App\Jobs\Reports\GenerateReportExport;
use App\Models\AdminExport;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Export an A09 report (P9.1a; PRD A09 "CSV/Excel export with formula neutralisation, audited,
 * large exports queued & emailed"). Needs `reports.export` — a refusal is audited and logged.
 * Up to `reports.export_sync_max_rows` rows the caller streams it now (returns null); above that a
 * background export is created (returns it) and its owner is emailed when it is ready.
 */
final class ExportReport
{
    public const QUEUED_PER_HOUR = 10;

    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws AuthorizationException */
    public function authorize(AdminUser $admin, ReportKey $report): void
    {
        if (Gate::forUser($admin)->denies('reports.export')) {
            $this->audit->record('reports.export_denied', null, after: ['report' => $report->value], actor: $admin, subjectLabel: $report->value);
            Log::warning('Report export refused: missing reports.export', ['admin_id' => $admin->id]);

            throw new AuthorizationException(__('You do not have permission to export reports.'));
        }
    }

    /**
     * @return AdminExport|null null = small enough: stream it now (after streamed())
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, ReportKey $report, ReportRange $range, ExportFormat $format): ?AdminExport
    {
        $this->authorize($admin, $report);
        $rows = $this->reports->for($report)->exportRows($range);

        if ($rows <= Settings::int(SettingKey::ReportsExportSyncMaxRows)) {
            return null;
        }

        // Each background export is real work on the media queue: at most QUEUED_PER_HOUR per admin.
        $bucket = 'report-export-queued:'.$admin->id;
        if ($this->limiter->tooManyAttempts($bucket, self::QUEUED_PER_HOUR)) {
            throw new TooManyRequestsHttpException($this->limiter->availableIn($bucket), __('You have asked for several large exports this hour. Please try again later.'));
        }
        $this->limiter->hit($bucket, 3600);

        $export = new AdminExport;   // every field server-written
        $export->forceFill([
            'code' => 'REX'.Str::upper(Str::random(9)),
            'admin_user_id' => $admin->id,
            'report' => $report->value,
            'params' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString()],
            'format' => $format,
            'status' => AdminExportStatus::Queued,
        ])->save();

        $this->audit->record('reports.export_queued', $export, after: $this->summary($report, $range, $format, $rows), actor: $admin, subjectLabel: $export->code);
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /** Audit a download streamed straight away. */
    public function streamed(AdminUser $admin, ReportKey $report, ReportRange $range, ExportFormat $format, int $rows): void
    {
        $this->audit->record('reports.exported', null, after: $this->summary($report, $range, $format, $rows), actor: $admin, subjectLabel: $report->value);
    }

    /** @return array{report: string, from: string, to: string, format: string, rows: int} */
    private function summary(ReportKey $report, ReportRange $range, ExportFormat $format, int $rows): array
    {
        return ['report' => $report->value, 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'format' => $format->value, 'rows' => $rows];
    }
}
