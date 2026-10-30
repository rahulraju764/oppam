<?php

declare(strict_types=1);

namespace App\Actions\Broker\Safety;

use App\Enums\ImportsPauseReason;
use App\Events\Broker\ImportsPaused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Pause a bureau's bulk imports (§11A B.14 `Safety\PauseBrokerImports`): automatically when a batch's
 * profiles are rejected too often (R-M13-32, BatchModerationTally), or from A07 (R-A07-9 — the admin
 * screen SetBrokerImportsPause, with the admin's typed note). Uploads, mappings, fixes and new imports are refused while
 * paused; single-profile creation still works. Already paused → nothing changes (the first reason
 * stays). Audited; the bureau sees a live banner (ImportsPaused) and A02 shows an alert.
 */
final class PauseBrokerImports
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool whether this call paused the imports */
    public function handle(Broker $broker, ImportsPauseReason $reason, ?BrokerImportBatch $batch = null, ?AdminUser $admin = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($broker, $reason, $batch, $admin, $note): bool {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            if ($locked->imports_paused_at !== null) {
                return false;
            }

            $locked->forceFill([
                'imports_paused_at' => now(),
                'imports_paused_reason' => $reason,
                'imports_paused_batch_id' => $batch?->id,
            ])->save();

            $this->audit->record('broker.imports_paused', $locked, after: array_filter([
                'reason' => $reason->value, 'batch' => $batch?->code,
                'approved' => $batch?->rows_approved, 'rejected' => $batch?->rows_rejected,
            ], fn (mixed $v): bool => $v !== null), reason: $note, actor: $admin, subjectLabel: $locked->code);

            ImportsPaused::dispatch($locked->id, $reason->value, $batch?->code);

            return true;
        });
    }
}
