<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\ReportRegistry;
use App\Enums\AdminExportStatus;
use App\Enums\ReportKey;
use App\Models\AdminExport;
use App\Notifications\Admin\ReportExportReady;
use App\Services\Reports\ReportFileWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Builds a large A09 report export (P9.1a) on the private disk, marks it READY for 24 hours and
 * emails its admin (the mail links to the Exports page — no token travels by email). A failure
 * marks it FAILED (the admin sees it on that page); no retry — they can ask again.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const READY_HOURS = 24;

    public int $tries = 1;

    /** Below the queue's retry_after (960 s). */
    public int $timeout = 900;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('media');
    }

    public function handle(ReportRegistry $reports, ReportFileWriter $writer): void
    {
        $export = AdminExport::query()->with('admin')->find($this->exportId);
        if ($export === null || $export->status !== AdminExportStatus::Queued) {
            return;
        }

        $disk = Storage::disk((string) config('oppam.media.private_disk'));
        $path = 'reports/exports/'.$export->code.'.'.$export->format->value;
        $temp = tmpfile();

        try {
            $key = ReportKey::from($export->report);
            $range = ReportRange::of($export->params['from'], $export->params['to']);
            $stream = $temp !== false ? $temp : throw new RuntimeException('No temporary file.');
            $rows = $writer->write($reports->for($key)->exportTables($range, $export->admin), $export->format, $stream, $key->label().' — '.$range->label());
            rewind($stream);
            $disk->writeStream($path, $stream);
        } catch (Throwable $e) {
            Log::warning('Report export failed', ['export' => $export->code, 'exception' => $e::class]);
            $export->forceFill(['status' => AdminExportStatus::Failed])->save();

            return;
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }

        DB::transaction(function () use ($export, $path, $rows): void {
            $export->forceFill([
                'status' => AdminExportStatus::Ready,
                'path' => $path,
                'rows' => $rows,
                'ready_at' => now(),
                'expires_at' => now()->addHours(self::READY_HOURS),
            ])->save();
        });

        $export->admin?->notify(new ReportExportReady($export));
    }

    /** Killed by its timeout or a worker crash: the admin sees "failed" instead of a row stuck "being prepared". */
    public function failed(?Throwable $e): void
    {
        AdminExport::query()->whereKey($this->exportId)->where('status', AdminExportStatus::Queued->value)
            ->update(['status' => AdminExportStatus::Failed->value, 'updated_at' => now()]);
    }
}
