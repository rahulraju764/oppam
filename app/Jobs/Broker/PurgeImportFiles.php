<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Enums\ImportBatchStatus;
use App\Enums\SettingKey;
use App\Models\BrokerImportBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\ImportFileVault;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * "Source files are purged from storage after 30 days" (§11A B.9, B.23 daily job): every batch older
 * than `broker.import_file_retention_days` loses its sheet, photo zip and re-uploaded fixes (the
 * encrypted `imports/{batch}/` folder); `files_purged_at` records it. Batches a job is working on
 * right now wait for tomorrow. Idempotent: purged batches are skipped. One audit row per run.
 */
final class PurgeImportFiles implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ImportFileVault $vault, AuditLogger $audit): void
    {
        $purged = 0;

        BrokerImportBatch::query()
            ->whereNull('files_purged_at')
            ->whereNotIn('status', [ImportBatchStatus::Validating, ImportBatchStatus::Importing])
            ->where('created_at', '<=', now()->subDays(Settings::int(SettingKey::BrokerImportFileRetentionDays)))
            ->lazyById(100)
            ->each(function (BrokerImportBatch $batch) use ($vault, &$purged): void {
                try {
                    $vault->purge($batch->id);
                    $batch->forceFill(['sheet_path' => null, 'photos_zip_path' => null, 'fix_sheet_path' => null, 'files_purged_at' => now()])->save();
                    $purged++;
                } catch (Throwable $e) {
                    report($e);   // retried tomorrow; the rest still run
                }
            });

        if ($purged > 0) {
            $audit->record('broker.import_files_purged', after: ['batches' => $purged]);
        }
    }
}
