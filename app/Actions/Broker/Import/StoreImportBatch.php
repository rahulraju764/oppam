<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportFileInspector;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ParseImportBatch;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\ImportFileVault;
use App\Services\Sequences\SequenceAllocator;
use App\Support\Facades\Feature;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Step 1 of a bulk import (§11A B.9): the sheet (+ an optional photo zip) becomes batch B-0042,
 * UPLOADED, and the validation job is queued on `imports`.
 *  - `profiles.import` — which also needs an ACTIVE, KYC-verified bureau (R-M13-26, BureauGate);
 *    the `broker.bulk_import` flag; the bureau's imports not paused by A07 (R-A07-9);
 *  - the files are checked from their bytes (ImportFileInspector: real xlsx / UTF-8 csv ≤ 10 MB;
 *    a real zip ≤ 200 MB of photos only, no nested archive, no zip-slip path);
 *  - they go to the PRIVATE disk, ENCRYPTED (ImportFileVault, CLAUDE.md rule 7 / B.18), under the
 *    batch's id with names chosen here, never the uploader's; a per-login hourly cap applies;
 *  - the same file (sha256) uploaded by this bureau before is remembered for a banner;
 *  - audited with the uploader. Nothing becomes a profile before step 5 (P7.7).
 */
final class StoreImportBatch
{
    private const PER_HOUR = 20;

    public function __construct(
        private readonly BureauGate $gate,
        private readonly ImportFileInspector $inspector,
        private readonly SequenceAllocator $sequences,
        private readonly ImportFileVault $vault,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `profiles.import` / KYC
     * @throws ImportRefused flag off, imports paused
     * @throws ValidationException keys `sheet`, `photos`
     */
    public function handle(User $uploader, UploadedFile $sheet, ?UploadedFile $photos): BrokerImportBatch
    {
        $staff = $this->gate->authorize($uploader, BureauPermission::ProfilesImport);
        /** @var Broker $broker */
        $broker = $staff->broker;

        if (! Feature::active(Flag::BrokerBulkImport)) {
            throw ImportRefused::notEnabled();
        }

        if ($broker->imports_paused_at !== null) {
            throw ImportRefused::paused();
        }

        $type = $this->inspector->sheet($sheet);
        if ($photos !== null) {
            $this->inspector->zip($photos);
        }

        $key = 'broker-import-uploads:'.$uploader->id;
        if ($this->limiter->tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['sheet' => __('You have uploaded many files in the last hour. Please try again later.')]);
        }

        $sha = (string) hash_file('sha256', (string) $sheet->getRealPath());
        $batchId = (string) Str::ulid();
        $folder = 'imports/'.$batchId;

        // Encrypted at rest before any database row points at them (CLAUDE.md rule 7, B.18).
        $sheetPath = $folder.'/sheet.'.$type.'.enc';
        $zipPath = $photos !== null ? $folder.'/photos.zip.enc' : null;
        $this->vault->seal((string) $sheet->getRealPath(), $sheetPath);
        if ($photos !== null && $zipPath !== null) {
            $this->vault->seal((string) $photos->getRealPath(), $zipPath);
        }

        $batch = DB::transaction(function () use ($uploader, $broker, $type, $sha, $batchId, $sheetPath, $zipPath, $photos): BrokerImportBatch {
            $batch = new BrokerImportBatch;
            $batch->id = $batchId;

            $batch->forceFill([
                'code' => 'B-'.str_pad((string) $this->sequences->next('import_batch_code', 1), 4, '0', STR_PAD_LEFT),
                'broker_id' => $broker->id,
                'uploaded_by_user_id' => $uploader->id,
                'sheet_path' => $sheetPath,
                'photos_zip_path' => $zipPath,
                'file_sha256' => $sha,
                'duplicate_of_batch_id' => BrokerImportBatch::query()->where('broker_id', $broker->id)->where('file_sha256', $sha)->latest()->value('id'),
                'status' => ImportBatchStatus::Uploaded,
            ])->save();

            $this->audit->record('broker.import_uploaded', $batch, after: [
                'broker' => $broker->code, 'photos' => $photos !== null, 'type' => $type,
            ], actor: $uploader, subjectLabel: $batch->code);

            return $batch;
        });

        $this->limiter->hit($key, 3600);
        ParseImportBatch::dispatch($batch->id)->afterCommit();

        return $batch;
    }
}
