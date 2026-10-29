<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\Import\ImportAllowance;
use App\Domain\Broker\Import\ImportBatchAccess;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ImportBatchRows;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Step 5 begins (§11A B.9): the uploader starts importing a READY batch. Refused unless the bureau
 * may import now (R-M13-26 `profiles.import`, the flag, not paused — R-A07-9 / R-M13-32), the uploader
 * attested consent (R-M13-27), some VALID / WARNING row is waiting, and today's allowance is not used
 * up (R-M13-31). Under the batch lock it becomes IMPORTING (a second click finds it so) and the
 * ImportBatchRows job is queued on `imports` after commit. Pressing it again after a stop (the daily
 * cap) resumes: rows already imported are never imported twice (R-M13-33).
 */
final class StartBatchImport
{
    public function __construct(
        private readonly ImportBatchAccess $access,
        private readonly ImportAllowance $allowance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `profiles.import` / KYC
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused
     */
    public function handle(User $actor, string $batchCode): BrokerImportBatch
    {
        $staff = $this->access->staff($actor);
        /** @var Broker $broker */
        $broker = $staff->broker;

        $batch = DB::transaction(function () use ($actor, $staff, $broker, $batchCode): BrokerImportBatch {
            $batch = $this->access->find($staff, $batchCode, lock: true);

            if ($batch->uploaded_by_user_id !== $actor->id) {
                throw ImportRefused::notUploader();
            }
            if ($batch->status !== ImportBatchStatus::Ready) {
                throw ImportRefused::wrongStatus();
            }
            if ($batch->consent_attested_at === null) {
                throw ImportRefused::notAttested();
            }
            $waiting = BrokerImportRow::query()->where('batch_id', $batch->id)
                ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning])->exists();
            if (! $waiting) {
                throw ImportRefused::nothingToImport();
            }
            if ($this->allowance->remainingToday($broker) === 0) {
                throw ImportRefused::noAllowance();
            }

            $batch->forceFill([
                'status' => ImportBatchStatus::Importing,
                'import_started_at' => $batch->import_started_at ?? now(),
                'stop_reason' => null,
            ])->save();

            $this->audit->record('broker.import_started', $batch, after: ['waiting' => $batch->rows_valid + $batch->rows_warning], actor: $actor, subjectLabel: $batch->code);

            return $batch;
        });

        ImportBatchRows::dispatch($batch->id)->afterCommit();

        return $batch;
    }
}
