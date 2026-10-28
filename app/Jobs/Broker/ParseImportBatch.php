<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Domain\Broker\Import\CellParser;
use App\Domain\Broker\Import\ImportColumns;
use App\Domain\Broker\Import\ImportContext;
use App\Domain\Broker\Import\ImportRowValidator;
use App\Domain\Broker\Import\ImportSheetReader;
use App\Enums\ImportBatchStatus;
use App\Events\Broker\ImportProgress;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Services\Broker\ImportFileVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 2 of a bulk import (§11A B.9): read the sheet, map its headers, and check every row with the
 * single form's rule set (ImportRowValidator / ManagedProfileRules) — on the `imports` queue, in
 * chunks of CHUNK rows, broadcasting ImportProgress after each chunk so the bureau watches a live
 * progress bar.
 *
 *  - more than MAX_ROWS data rows → FAILED ("at most 500 rows per file");
 *  - a required column the headers don't give (and no saved mapping) → MAPPING: the bureau maps them
 *    (SaveColumnMapping), which queues this job again;
 *  - otherwise every row is stored with its status and messages → READY.
 * Re-running is safe: the batch's rows are rebuilt (nothing is imported before step 5). An unexpected
 * failure leaves the batch FAILED with a generic message; the reason is logged without cell contents.
 * It runs whatever happened to the uploader meanwhile (B.9: a deactivated uploader's job completes).
 */
final class ParseImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK = 100;

    public const MAX_ROWS = 500;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(ImportSheetReader $reader, ImportRowValidator $validator, ImportFileVault $vault): void
    {
        $batch = BrokerImportBatch::query()->find($this->batchId);
        if ($batch === null || ! in_array($batch->status, [ImportBatchStatus::Uploaded, ImportBatchStatus::Validating], true)) {
            return;   // cancelled, already parsed, or waiting for a mapping
        }

        // The files are encrypted at rest: plain copies exist only in temp files while this job reads them.
        $sheetFile = null;
        $zipFile = null;

        try {
            $batch->forceFill(['status' => ImportBatchStatus::Validating, 'error_message' => null])->save();
            $sheetFile = $vault->unseal((string) $batch->sheet_path, self::sheetType((string) $batch->sheet_path));
            $zipFile = $batch->photos_zip_path !== null ? $vault->unseal($batch->photos_zip_path, 'zip') : null;

            $this->parse($batch, $reader, $validator, $sheetFile, $zipFile);
        } catch (Throwable $e) {
            Log::error('Import batch parsing failed', ['batch' => $batch->code, 'exception' => $e::class, 'line' => $e->getLine()]);
            $this->finish($batch, ImportBatchStatus::Failed, self::genericFailure());
        } finally {
            ImportFileVault::forget($sheetFile);
            ImportFileVault::forget($zipFile);
        }
    }

    /**
     * The worker died (memory, timeout) without reaching the catch above: the batch must not stay
     * VALIDATING for ever — it ends FAILED with the same generic message.
     */
    public function failed(?Throwable $e): void
    {
        $batch = BrokerImportBatch::query()->find($this->batchId);
        if ($batch !== null && in_array($batch->status, [ImportBatchStatus::Uploaded, ImportBatchStatus::Validating], true)) {
            Log::error('Import batch parsing job failed', ['batch' => $batch->code, 'exception' => $e !== null ? $e::class : null]);
            $this->finish($batch, ImportBatchStatus::Failed, self::genericFailure());
        }
    }

    public static function genericFailure(): string
    {
        return __('We could not read this file. Download the template and try again.');
    }

    /** `imports/…/sheet.xlsx.enc` → xlsx. */
    private static function sheetType(string $path): string
    {
        return pathinfo(basename($path, '.enc'), PATHINFO_EXTENSION);
    }

    private function parse(BrokerImportBatch $batch, ImportSheetReader $reader, ImportRowValidator $validator, string $sheetFile, ?string $zipFile): void
    {
        $type = self::sheetType((string) $batch->sheet_path);

        // B.9 limits, checked BEFORE the sheet is loaded: a 10 MB file can hold millions of cells.
        if ($reader->tooLarge($sheetFile, $type, self::MAX_ROWS)) {
            $this->finish($batch, ImportBatchStatus::Failed, __('This file has more than :max rows. Upload at most :max rows per file.', ['max' => self::MAX_ROWS]));

            return;
        }

        $sheet = $reader->read($sheetFile, $type);
        $headers = $sheet['headers'];

        $problem = ImportColumns::headerProblem($headers);
        if ($problem !== null) {
            $this->finish($batch, ImportBatchStatus::Failed, $problem, $headers);

            return;
        }
        $rows = $sheet['rows'];
        $total = count($rows);

        if ($total === 0) {
            $this->finish($batch, ImportBatchStatus::Failed, __('The file has no rows to import.'), $headers);

            return;
        }

        if ($total > self::MAX_ROWS) {
            $this->finish($batch, ImportBatchStatus::Failed, __('This file has :count rows. Upload at most :max rows per file.', ['count' => $total, 'max' => self::MAX_ROWS]), $headers);

            return;
        }

        $map = self::usableMap($batch->column_map ?? [], $headers) ?: ImportColumns::autoMap($headers);
        if (ImportColumns::missing($map) !== []) {
            $batch->forceFill(['headers' => $headers, 'column_map' => $map, 'rows_total' => $total])->save();
            $this->finish($batch, ImportBatchStatus::Mapping);

            return;
        }

        $position = array_flip($headers);
        $cellsOf = function (array $row) use ($map, $position): array {
            $cells = [];
            foreach ($map as $field => $header) {
                $cells[$field] = $row[$position[$header] ?? -1] ?? null;
            }

            return $cells;
        };

        $context = ImportContext::load(
            $batch->broker_id,
            array_map(fn (array $row): string => (string) ($cellsOf($row)['client_mobile'] ?? ''), array_values($rows)),
            $zipFile !== null ? ImportSheetReader::zipEntries($zipFile) : null,
        );

        BrokerImportRow::query()->where('batch_id', $batch->id)->delete();
        $batch->forceFill(['headers' => $headers, 'column_map' => $map, 'rows_total' => $total, 'rows_valid' => 0, 'rows_warning' => 0, 'rows_error' => 0])->save();

        $counts = ['VALID' => 0, 'WARNING' => 0, 'ERROR' => 0];
        $processed = 0;
        foreach (array_chunk($rows, self::CHUNK, preserve_keys: true) as $chunk) {
            $records = [];
            foreach ($chunk as $rowNumber => $row) {
                $raw = [];
                foreach ($headers as $i => $header) {
                    if ($header !== '') {   // a column without a header is not part of the import
                        $raw[$header] = CellParser::neutralise(CellParser::text($row[$i] ?? null));
                    }
                }
                $result = $validator->check($cellsOf($row), $context);
                $counts[$result['status']->value]++;
                $records[] = [
                    'batch_id' => $batch->id,
                    'row_number' => $rowNumber,
                    'data' => json_encode(['raw' => $raw, 'values' => $result['values']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'status' => $result['status']->value,
                    'messages' => json_encode($result['messages'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }

            $processed += count($chunk);
            DB::transaction(function () use ($batch, $records, $counts): void {
                BrokerImportRow::query()->insert($records);
                $batch->forceFill(['rows_valid' => $counts['VALID'], 'rows_warning' => $counts['WARNING'], 'rows_error' => $counts['ERROR']])->save();
            });
            $this->progress($batch, $processed);
        }

        $this->finish($batch, ImportBatchStatus::Ready);
    }

    /**
     * A saved mapping still usable for these headers (every mapped header present), or [].
     *
     * @param  array<string, mixed>  $map
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private static function usableMap(array $map, array $headers): array
    {
        $clean = array_filter($map, fn (mixed $h, string $field): bool => is_string($h) && in_array($h, $headers, true)
            && array_key_exists($field, ImportColumns::COLUMNS), ARRAY_FILTER_USE_BOTH);

        return ImportColumns::missing($clean) === [] ? $clean : [];
    }

    /** @param  list<string>|null  $headers */
    private function finish(BrokerImportBatch $batch, ImportBatchStatus $status, ?string $error = null, ?array $headers = null): void
    {
        $batch->forceFill(array_filter([
            'status' => $status,
            'error_message' => $error,
            'headers' => $headers,
        ], fn (mixed $v): bool => $v !== null))->save();

        $this->progress($batch->refresh(), $status === ImportBatchStatus::Ready ? $batch->rows_total : 0);
    }

    private function progress(BrokerImportBatch $batch, int $processed): void
    {
        ImportProgress::dispatch($batch->broker_id, $batch->code, $batch->status->value, $processed, $batch->rows_total,
            $batch->rows_valid, $batch->rows_warning, $batch->rows_error);
    }
}
