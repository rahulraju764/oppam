<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Imports;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Actions\Broker\Import\CancelImportBatch;
use App\Exceptions\Broker\ImportRefused;
use App\Models\AdminUser;
use App\Models\BrokerImportBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 Imports monitor "cancel READY batch" (§11A B.12): the bureau's own cancel
 * (CancelImportBatch::cancel — nothing imported → CANCELLED and files deleted; partly imported →
 * closed, profiles kept, the rest skipped), by an admin. Found by batch code within the given
 * bureau (codes are per bureau). Needs `brokers.imports.manage`; typed reason, audited as the admin.
 */
final class CancelImportBatchAsAdmin
{
    use ManagesBureaus;

    public function __construct(private readonly CancelImportBatch $cancellation) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused not cancellable in its status
     */
    public function handle(AdminUser $admin, string $brokerId, string $batchCode, string $reason): BrokerImportBatch
    {
        $this->authorizeFor($admin, 'brokers.imports.manage');
        $reason = $this->validatedReason($reason);

        $batch = DB::transaction(function () use ($admin, $brokerId, $batchCode, $reason): BrokerImportBatch {
            $batch = BrokerImportBatch::query()->where('broker_id', $brokerId)->where('code', $batchCode)->lockForUpdate()->firstOrFail();

            return $this->cancellation->cancel($batch, $admin, 'brokers', $reason);
        });

        $this->cancellation->purgeIfCancelled($batch);

        return $batch;
    }
}
