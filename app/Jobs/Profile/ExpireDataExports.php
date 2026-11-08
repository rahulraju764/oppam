<?php

declare(strict_types=1);

namespace App\Jobs\Profile;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Hourly (routes/console.php): every READY "my data" zip past its 24 hours is deleted from the
 * private disk and the row marked EXPIRED (M14); a request still QUEUED after an hour was lost and
 * is marked FAILED. Idempotent; one failing file doesn't stop the rest.
 */
final class ExpireDataExports implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $disk = Storage::disk(GenerateDataExportJob::disk());

        // Requests lost on the way (worker killed, job dropped) stop blocking a new one.
        DataExport::query()
            ->where('status', DataExportStatus::Queued->value)
            ->where('created_at', '<=', now()->subMinutes(GenerateDataExportJob::STALE_MINUTES))
            ->update(['status' => DataExportStatus::Failed->value, 'updated_at' => now()]);

        DataExport::query()
            ->where('status', DataExportStatus::Ready->value)
            ->where('expires_at', '<=', now())
            ->lazyById(100)
            ->each(function (DataExport $export) use ($disk): void {
                try {
                    if ($export->file_path !== null) {
                        $disk->delete($export->file_path);
                    }
                    $export->forceFill(['status' => DataExportStatus::Expired, 'file_path' => null])->save();
                } catch (Throwable $e) {
                    report($e);
                }
            });
    }
}
