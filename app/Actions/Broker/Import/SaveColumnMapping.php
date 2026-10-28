<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ParseImportBatch;
use App\Models\BrokerImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Feature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The bureau matches its sheet's headers to the template's fields (§11A B.9 "manual mapping
 * screen if they differ"): `profiles.import`, a batch of the caller's bureau (else 404) waiting in
 * MAPPING. Every field must be a template field and every header one of the sheet's own headers,
 * each header used once; all required fields must be mapped. Then validation is queued again.
 */
final class SaveColumnMapping
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $mapping  field => sheet header ('' = not in the sheet)
     *
     * @throws HttpException 403 without `profiles.import`
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused not waiting for a mapping
     * @throws ValidationException
     */
    public function handle(User $actor, string $batchCode, array $mapping): BrokerImportBatch
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesImport);

        if (! Feature::active(Flag::BrokerBulkImport)) {
            throw ImportRefused::notEnabled();
        }
        if ($staff->broker?->imports_paused_at !== null) {
            throw ImportRefused::paused();   // R-A07-9
        }

        $batch = DB::transaction(function () use ($actor, $staff, $batchCode, $mapping): BrokerImportBatch {
            $batch = BrokerImportBatch::query()->where('broker_id', $staff->broker_id)->where('code', $batchCode)->lockForUpdate()->firstOrFail();

            if ($batch->status !== ImportBatchStatus::Mapping) {
                throw ImportRefused::wrongStatus();
            }

            $headers = $batch->headers ?? [];
            $clean = [];
            foreach ($mapping as $field => $header) {
                if (! array_key_exists((string) $field, ImportColumns::COLUMNS) || ! is_string($header) || $header === '') {
                    continue;
                }
                if (! in_array($header, $headers, true)) {
                    throw ValidationException::withMessages(["mapping.{$field}" => __('Choose one of the sheet’s columns.')]);
                }
                if (in_array($header, $clean, true)) {
                    throw ValidationException::withMessages(["mapping.{$field}" => __('Each column can be used for one field only.')]);
                }
                $clean[$field] = $header;
            }

            $missing = ImportColumns::missing($clean);
            if ($missing !== []) {
                throw ValidationException::withMessages(array_combine(
                    array_map(fn (string $f): string => "mapping.{$f}", $missing),
                    array_fill(0, count($missing), __('This field is required — choose the column that holds it.')),
                ));
            }

            $batch->forceFill(['column_map' => $clean, 'status' => ImportBatchStatus::Uploaded])->save();
            $this->audit->record('broker.import_mapped', $batch, after: ['fields' => count($clean)], actor: $actor, subjectLabel: $batch->code);

            return $batch;
        });

        ParseImportBatch::dispatch($batch->id)->afterCommit();

        return $batch;
    }
}
