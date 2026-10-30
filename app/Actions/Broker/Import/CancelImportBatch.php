<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportBatchAccess;
use App\Domain\Broker\Import\ImportRowRechecker;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\AdminUser;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\ImportFileVault;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Cancel before import" (§11A B.9 batch history): a batch nothing was imported from yet becomes
 * CANCELLED and its files are deleted. Allowed to the uploader, the Owner and Managers (decisions
 * 2026-10-28) — also when the bureau's KYC was revoked or its imports are paused (B.9: "KYC revoked
 * while READY → batch can only be cancelled"), so the role check skips the verified-bureau part.
 */
final class CancelImportBatch
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ImportBatchAccess $access,
        private readonly ImportFileVault $vault,
        private readonly ImportRowRechecker $rechecker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the role permission
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused already imported from, or not the uploader / Owner / Manager
     */
    public function handle(User $actor, string $batchCode): BrokerImportBatch
    {
        $staff = $this->gate->authorizeRole($actor, BureauPermission::ProfilesImport);

        $batch = DB::transaction(function () use ($actor, $staff, $batchCode): BrokerImportBatch {
            $batch = $this->access->find($staff, $batchCode, lock: true);

            if ($batch->uploaded_by_user_id !== $actor->id && ! in_array($staff->role, [BureauRole::Owner, BureauRole::Manager], true)) {
                throw ImportRefused::notUploader();
            }

            return $this->cancel($batch, $actor, 'broker');
        });

        $this->purgeIfCancelled($batch);

        return $batch;
    }

    /**
     * Cancel (nothing imported yet) or close (partly imported) a batch the caller has locked, inside
     * its transaction — shared with A07 (Admin\Brokers\Imports\CancelImportBatchAsAdmin, audit
     * prefix `brokers`). Call purgeIfCancelled() after the commit.
     *
     * @throws ImportRefused not cancellable in its status
     */
    public function cancel(BrokerImportBatch $batch, User|AdminUser $actor, string $auditPrefix, ?string $reason = null): BrokerImportBatch
    {
        if (! $batch->isCancellable()) {
            throw ImportRefused::wrongStatus();
        }

        if ($batch->rows_imported > 0) {
            // Partly imported (the daily cap stopped it): close it — the profiles stay, the rest is skipped.
            BrokerImportRow::query()->where('batch_id', $batch->id)
                ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning, ImportRowStatus::Error])
                ->update(['status' => ImportRowStatus::Skipped, 'updated_at' => now()]);
            $this->rechecker->recount($batch);
            $batch->forceFill(['status' => ImportBatchStatus::Completed, 'stop_reason' => null])->save();
            $this->audit->record($auditPrefix.'.import_closed', $batch, after: ['imported' => $batch->rows_imported, 'skipped' => $batch->rows_skipped], reason: $reason, actor: $actor, subjectLabel: $batch->code);

            return $batch;
        }

        $batch->forceFill(['status' => ImportBatchStatus::Cancelled, 'sheet_path' => null, 'photos_zip_path' => null, 'fix_sheet_path' => null, 'files_purged_at' => now()])->save();
        $this->audit->record($auditPrefix.'.import_cancelled', $batch, after: ['rows' => $batch->rows_total], reason: $reason, actor: $actor, subjectLabel: $batch->code);

        return $batch;
    }

    /** After the commit: a failed write never loses the files. */
    public function purgeIfCancelled(BrokerImportBatch $batch): void
    {
        if ($batch->status === ImportBatchStatus::Cancelled) {
            $this->vault->purge($batch->id);
        }
    }
}
