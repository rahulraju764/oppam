<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\Import\ImportBatchAccess;
use App\Enums\ImportBatchStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\BrokerImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Step 4 of a bulk import (§11A B.9, R-M13-27): the uploader confirms that every client in the file
 * agreed to a profile on Oppam. Stamped with that login and the time (R-M13-23); required before the
 * import starts — and every row must also say `client_consent = YES` (checked per row by the rules).
 */
final class AttestBatchConsent
{
    public function __construct(
        private readonly ImportBatchAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `profiles.import`
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused flag off, paused, not READY, not the uploader
     */
    public function handle(User $actor, string $batchCode): BrokerImportBatch
    {
        $staff = $this->access->staff($actor);

        return DB::transaction(function () use ($actor, $staff, $batchCode): BrokerImportBatch {
            $batch = $this->access->find($staff, $batchCode, lock: true);

            if ($batch->uploaded_by_user_id !== $actor->id) {
                throw ImportRefused::notUploader();
            }
            if ($batch->status !== ImportBatchStatus::Ready) {
                throw ImportRefused::wrongStatus();
            }
            if ($batch->consent_attested_at !== null) {
                return $batch;
            }

            $batch->forceFill(['consent_attested_at' => now(), 'consent_attested_by_user_id' => $actor->id])->save();
            $this->audit->record('broker.import_consent_attested', $batch, after: ['rows' => $batch->rows_valid + $batch->rows_warning], actor: $actor, subjectLabel: $batch->code);

            return $batch;
        });
    }
}
