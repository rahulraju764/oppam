<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Actions\Broker\ManagedProfiles\CreateManagedProfile;
use App\Actions\Profile\Photos\UploadProfilePhoto;
use App\Actions\Profile\SaveAboutDetails;
use App\Actions\Profile\SaveCareerDetails;
use App\Data\Broker\ImportedRowData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportAllowance;
use App\Domain\Broker\Import\ImportColumns;
use App\Domain\Broker\Import\ImportRowRechecker;
use App\Domain\Broker\Import\ImportRowValidator;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStopReason;
use App\Events\Broker\ImportCompleted;
use App\Events\Broker\ImportProgress;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Services\Broker\ImportFileVault;
use App\Support\Facades\Feature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use ZipArchive;

/**
 * Step 5 of a bulk import (§11A B.9): every VALID / WARNING row of an IMPORTING batch becomes a
 * DRAFT managed profile — on the `imports` queue, in chunks of CHUNK rows with live ImportProgress.
 *
 *  - The same write path as "Add profile" (R-M13-28): CreateManagedProfile::createFor as the uploader
 *    (consent stamps, claim key free under lock — R-M13-29, DRAFT — R-M13-30, the batch stamped),
 *    then the location / career and about sections through SaveCareerDetails / SaveAboutDetails.
 *    Each row is ONE transaction; a row with `imported_profile_id` is never imported again
 *    (R-M13-33), so a re-run or a resume after the daily cap is safe.
 *  - A row the rules now refuse (e.g. its mobile reached Oppam since the check) becomes ERROR with
 *    the message; the run goes on.
 *  - Photos named in the row go through the normal media pipeline (UploadProfilePhoto::storeChecked:
 *    re-encoded, PENDING at A04) after the row's commit; a photo that can't be used adds a WARNING
 *    message to the row, the profile stays.
 *  - Before every chunk (decisions 2026-10-28): the bureau may still import (ACTIVE, KYC-verified, not
 *    paused, flag on) — else it stops, the rest stays importable and the batch is READY again
 *    (BUREAU_BLOCKED: only cancel works while it lasts); the uploader still holds `profiles.import`
 *    — else the rest is SKIPPED and the batch COMPLETED (UPLOADER_REMOVED; no permission is
 *    bypassed); today's allowance is left — else READY with DAILY_CAP (R-M13-31).
 *  - At the end the leftover ERROR rows become SKIPPED and the batch COMPLETED; ImportCompleted tells
 *    the bureau. A worker that dies leaves the batch READY (failed()) — pressing Import resumes.
 */
final class ImportBatchRows implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK = 100;

    private const IMPORTED = 1;

    private const OVER_CAP = 2;

    private const DENIED = 3;

    public int $tries = 1;

    /** Below the redis queue's retry_after (960 s): a long run is never handed to a second worker meanwhile. */
    public int $timeout = 900;

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(
        CreateManagedProfile $create,
        SaveCareerDetails $career,
        SaveAboutDetails $about,
        UploadProfilePhoto $photos,
        ImportAllowance $allowance,
        ImportRowRechecker $rechecker,
        BureauGate $gate,
        ImportFileVault $vault,
    ): void {
        $batch = BrokerImportBatch::query()->find($this->batchId);
        if ($batch === null || $batch->status !== ImportBatchStatus::Importing) {
            return;
        }

        $uploader = User::query()->find($batch->uploaded_by_user_id);
        $ids = BrokerImportRow::query()->where('batch_id', $batch->id)
            ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning])
            ->whereNull('imported_profile_id')
            ->orderBy('row_number')->pluck('id')->all();

        $zipFile = null;
        $zip = null;

        try {
            if ($batch->photos_zip_path !== null && $batch->files_purged_at === null) {
                $zipFile = $vault->unseal($batch->photos_zip_path, 'zip');
                $zip = new ZipArchive;
                if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
                    $zip = null;
                }
            }

            $processed = 0;
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $gate->forget();   // a long-lived worker: never decide on a previous job's memo
                $staff = null;
                $stop = $this->blocked($batch, $uploader, $gate, $staff);
                if ($stop === null && $allowance->remainingToday($batch->broker()->firstOrFail()) === 0) {
                    $stop = ImportStopReason::DailyCap;
                }
                if ($stop !== null || $staff === null || $uploader === null) {
                    $this->finish($batch, $rechecker, $stop ?? ImportStopReason::UploaderRemoved);

                    return;
                }

                foreach ($chunk as $id) {
                    $outcome = $this->importRow($batch, (int) $id, $uploader, $staff, $create, $career, $about, $photos, $zip, $allowance);
                    if ($outcome !== self::IMPORTED) {
                        $this->finish($batch, $rechecker, $outcome === self::OVER_CAP ? ImportStopReason::DailyCap : ImportStopReason::UploaderRemoved);

                        return;
                    }
                    $processed++;
                }

                $rechecker->recount($batch);
                ImportProgress::dispatch($batch->broker_id, $batch->code, ImportBatchStatus::Importing->value, $processed, count($ids),
                    $batch->rows_valid, $batch->rows_warning, $batch->rows_error);
            }

            $this->finish($batch, $rechecker, null);
        } finally {
            $zip?->close();
            ImportFileVault::forget($zipFile);
        }
    }

    /**
     * The worker died (memory, timeout): the rows already imported stay; the batch goes back to READY
     * so the uploader can press Import again (idempotent per row, R-M13-33).
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Import batch rows failed', ['batch' => $this->batchId, 'exception' => $e !== null ? $e::class : null]);

        BrokerImportBatch::query()->whereKey($this->batchId)->where('status', ImportBatchStatus::Importing)
            ->update(['status' => ImportBatchStatus::Ready->value, 'error_message' => __('Importing stopped unexpectedly. Press Import to continue — rows already imported are kept.'), 'updated_at' => now()]);
    }

    /** Why the run must stop now, or null; sets $staff to the uploader's bureau login when it may go on. */
    private function blocked(BrokerImportBatch $batch, ?User $uploader, BureauGate $gate, ?BrokerStaff &$staff): ?ImportStopReason
    {
        $broker = Broker::query()->find($batch->broker_id);
        if ($broker === null || ! $broker->isVerifiedAndActive() || $broker->imports_paused_at !== null || ! Feature::active(Flag::BrokerBulkImport)) {
            return ImportStopReason::BureauBlocked;
        }

        if ($uploader === null || ! $uploader->isActive() || ! $gate->allows($uploader, BureauPermission::ProfilesImport)) {
            return ImportStopReason::UploaderRemoved;
        }

        $staff = $gate->staff($uploader);
        if ($staff === null || $staff->broker_id !== $batch->broker_id) {
            return ImportStopReason::UploaderRemoved;
        }

        return null;
    }

    /**
     * @return self::IMPORTED|self::OVER_CAP|self::DENIED IMPORTED also for a row skipped (done before) or
     *                                                    refused by the rules (now ERROR) — the run goes on
     */
    private function importRow(
        BrokerImportBatch $batch,
        int $id,
        User $uploader,
        BrokerStaff $staff,
        CreateManagedProfile $create,
        SaveCareerDetails $career,
        SaveAboutDetails $about,
        UploadProfilePhoto $photos,
        ?ZipArchive $zip,
        ImportAllowance $allowance,
    ): int {
        $data = null;
        $overCap = false;

        try {
            $profile = DB::transaction(function () use ($batch, $id, $uploader, $staff, $create, $career, $about, $allowance, &$data, &$overCap): ?Profile {
                // R-M13-31 under the bureau lock: two batches of one bureau importing at once can't both
                // spend the same allowance (they take turns, row by row).
                $broker = Broker::query()->whereKey($batch->broker_id)->lockForUpdate()->firstOrFail();
                if ($allowance->remainingToday($broker) === 0) {
                    $overCap = true;

                    return null;
                }

                $row = BrokerImportRow::query()->whereKey($id)->lockForUpdate()->first();
                if ($row === null || $row->imported_profile_id !== null || ! in_array($row->status, [ImportRowStatus::Valid, ImportRowStatus::Warning], true)) {
                    return null;   // imported by an earlier run, or changed meanwhile (R-M13-33)
                }

                $data = ImportedRowData::fromValues(is_array($row->data['values'] ?? null) ? $row->data['values'] : []);
                $profile = $create->createFor($uploader, $staff, $data->managed, $data->basic, $batch);
                $career->handle($uploader, $profile, $data->career, partial: true);
                $about->handle($uploader, $profile, $data->about, partial: true);

                $row->forceFill(['status' => ImportRowStatus::Imported, 'imported_profile_id' => $profile->id])->save();

                return $profile;
            });
        } catch (ValidationException $e) {
            $this->markError($id, $e);

            return self::IMPORTED;
        } catch (AuthorizationException) {
            return self::DENIED;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                return self::DENIED;
            }

            throw $e;
        }

        if ($overCap) {
            return self::OVER_CAP;
        }

        if ($profile !== null && $data !== null && $zip !== null && $data->photoFiles !== []) {
            $this->attachPhotos($id, $profile, $uploader, $data->photoFiles, $photos, $zip);
        }

        return self::IMPORTED;
    }

    /** The rules refused the row at import time: ERROR with their messages (the profile was rolled back). */
    private function markError(int $id, ValidationException $e): void
    {
        $messages = [];
        foreach ($e->errors() as $key => $errors) {
            $field = match ($key) {
                'clientPhone' => 'client_mobile',
                'consent' => 'client_consent',
                default => $key,
            };
            // Named by the template header, like the check's own messages (ImportRowValidator).
            $messages[] = ['field' => ImportColumns::COLUMNS[$field][0] ?? $field, 'level' => 'error', 'text' => (string) ($errors[0] ?? '')];
        }

        BrokerImportRow::query()->whereKey($id)->update([
            'status' => ImportRowStatus::Error->value,
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * The row's photos from the zip, first = primary, at most ImportRowValidator::MAX_PHOTOS. Only
     * names the zip holds (the upload already refused unsafe paths and non-images); each extracted to
     * a temp file and deleted after the pipeline re-encoded it.
     *
     * @param  list<string>  $names
     */
    private function attachPhotos(int $rowId, Profile $profile, User $uploader, array $names, UploadProfilePhoto $photos, ZipArchive $zip): void
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (! str_ends_with($entry, '/')) {
                $entries[mb_strtolower(basename($entry))] ??= $entry;
            }
        }

        $problems = [];
        foreach (array_slice($names, 0, ImportRowValidator::MAX_PHOTOS) as $name) {
            $entry = $entries[mb_strtolower(basename($name))] ?? null;
            if ($entry === null) {
                continue;   // already a WARNING from the check
            }

            $bytes = $zip->getFromName($entry);
            $temp = tempnam(sys_get_temp_dir(), 'oppam-photo-');
            if ($bytes === false || $temp === false) {
                $problems[] = $name;

                continue;
            }

            try {
                file_put_contents($temp, $bytes);
                $photos->storeChecked($uploader, $profile, new UploadedFile($temp, basename($entry), null, null, true));
            } catch (ValidationException|AuthorizationException) {
                $problems[] = $name;
            } finally {
                @unlink($temp);
            }
        }

        if ($problems !== []) {
            $row = BrokerImportRow::query()->find($rowId);
            if ($row !== null) {
                $row->forceFill(['messages' => [...($row->messages ?? []), [
                    'field' => 'photo_files', 'level' => 'warning',
                    'text' => __('These photos couldn’t be used: :names. Add photos on the profile instead.', ['names' => implode(', ', array_slice($problems, 0, 5))]),
                ]]])->save();
            }
        }
    }

    /**
     * End this run. null = every importable row done; DAILY_CAP / BUREAU_BLOCKED = the rest waits
     * (READY); UPLOADER_REMOVED = the rest is skipped (COMPLETED, decisions 2026-10-28).
     */
    private function finish(BrokerImportBatch $batch, ImportRowRechecker $rechecker, ?ImportStopReason $stop): void
    {
        $completes = $stop === null || $stop === ImportStopReason::UploaderRemoved;

        DB::transaction(function () use ($batch, $rechecker, $stop, $completes): void {
            BrokerImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($completes) {
                BrokerImportRow::query()->where('batch_id', $batch->id)
                    ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning, ImportRowStatus::Error])
                    ->update(['status' => ImportRowStatus::Skipped->value, 'updated_at' => now()]);
            }

            $rechecker->recount($batch);
            $batch->forceFill([
                'status' => $completes ? ImportBatchStatus::Completed : ImportBatchStatus::Ready,
                'stop_reason' => $stop,
                'error_message' => null,
            ])->save();

            ImportProgress::dispatch($batch->broker_id, $batch->code, $batch->status->value, $batch->rows_imported, $batch->rows_total,
                $batch->rows_valid, $batch->rows_warning, $batch->rows_error);
            ImportCompleted::dispatch($batch->broker_id, $batch->code, $batch->status->value, $batch->rows_imported,
                $batch->rows_skipped, $batch->rows_valid + $batch->rows_warning, $stop?->value);
        });
    }
}
