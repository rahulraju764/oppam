<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Exceptions\Broker\ImportRefused;
use App\Models\BrokerImportBatch;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Support\Facades\Feature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The checks every bulk-import step shares (§11A B.9, R-M13-26, R-M13-18): the login holds
 * `profiles.import` (ACTIVE, KYC-verified bureau — BureauGate), the `broker.bulk_import` flag is on,
 * and a batch is only ever the caller's bureau's, looked up by its code (another bureau's → 404).
 */
final class ImportBatchAccess
{
    public function __construct(private readonly BureauGate $gate) {}

    /**
     * @param  bool  $unpaused  refuse while the bureau's imports are paused (R-A07-9) — cancelling still works
     *
     * @throws HttpException 403 without the permission / KYC
     * @throws ImportRefused flag off, imports paused
     */
    public function staff(User $actor, bool $unpaused = true): BrokerStaff
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesImport);

        if (! Feature::active(Flag::BrokerBulkImport)) {
            throw ImportRefused::notEnabled();
        }
        if ($unpaused && $staff->broker?->imports_paused_at !== null) {
            throw ImportRefused::paused();
        }

        return $staff;
    }

    /** @throws ModelNotFoundException<BrokerImportBatch> */
    public function find(BrokerStaff $staff, string $code, bool $lock = false): BrokerImportBatch
    {
        return BrokerImportBatch::query()
            ->where('broker_id', $staff->broker_id)
            ->where('code', $code)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->firstOrFail();
    }
}
