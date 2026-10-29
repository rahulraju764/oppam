<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\Import\CellParser;
use App\Domain\Broker\Import\ImportBatchAccess;
use App\Domain\Broker\Import\ImportFileInspector;
use App\Domain\Broker\Import\ImportRowRechecker;
use App\Domain\Broker\Import\ImportSheetReader;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\ImportFileVault;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Download errors.xlsx, fix, and re-upload just those rows" (§11A B.9 step 3): the fixed file goes
 * into the SAME batch. Its `row` column names the batch rows it replaces; every other column whose
 * header is one of the batch's own replaces that cell (the `messages` column and unknown columns are
 * ignored). Only rows not yet imported can be replaced — any other row number refuses the whole file,
 * nothing written. The rows are then checked again by the same rules (ImportRowRechecker). The file
 * is checked from its bytes like the original (ImportFileInspector) and kept encrypted with the batch.
 */
final class ReuploadImportErrors
{
    private const PER_HOUR = 20;

    public function __construct(
        private readonly ImportBatchAccess $access,
        private readonly ImportFileInspector $inspector,
        private readonly ImportSheetReader $reader,
        private readonly ImportRowRechecker $rechecker,
        private readonly ImportFileVault $vault,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int the number of rows replaced
     *
     * @throws HttpException 403 without `profiles.import`
     * @throws ModelNotFoundException<BrokerImportBatch>
     * @throws ImportRefused flag off, paused, not READY
     * @throws ValidationException key `fixes`
     */
    public function handle(User $actor, string $batchCode, UploadedFile $file): int
    {
        $staff = $this->access->staff($actor);
        $batch = $this->access->find($staff, $batchCode);   // 404 before anything is read
        $refuse = fn (string $why): ValidationException => ValidationException::withMessages(['fixes' => $why]);

        try {
            $type = $this->inspector->sheet($file);
        } catch (ValidationException $e) {
            throw $refuse((string) collect($e->errors())->flatten()->first());
        }

        $key = 'broker-import-reupload:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, self::PER_HOUR)) {
            throw $refuse(__('You have uploaded many files in the last hour. Please try again later.'));
        }
        $this->limiter->hit($key, 3600);

        $path = (string) $file->getRealPath();
        if ($this->reader->tooLarge($path, $type, (int) $batch->rows_total)) {
            throw $refuse(__('This file has more rows than the batch.'));
        }
        $sheet = $this->reader->read($path, $type);
        $rowColumn = array_search('row', array_map(fn (string $h): string => mb_strtolower(trim($h)), $sheet['headers']), true);
        if ($rowColumn === false) {
            throw $refuse(__('Keep the “row” column from the errors file so each fix finds its row.'));
        }

        $replaced = DB::transaction(function () use ($actor, $staff, $batchCode, $sheet, $rowColumn, $refuse, $type, $file): int {
            $batch = $this->access->find($staff, $batchCode, lock: true);
            if ($batch->status !== ImportBatchStatus::Ready) {
                throw ImportRefused::wrongStatus();
            }

            $open = BrokerImportRow::query()->where('batch_id', $batch->id)
                ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning, ImportRowStatus::Error])
                ->get()->keyBy('row_number');
            $known = array_values(array_intersect($sheet['headers'], $batch->headers ?? []));

            $changed = [];
            $unknown = [];
            foreach ($sheet['rows'] as $cells) {
                $number = (int) CellParser::text($cells[$rowColumn] ?? null);
                if (! isset($open[$number])) {
                    $unknown[] = CellParser::text($cells[$rowColumn] ?? null);

                    continue;
                }
                $raw = $changed[$number] ?? (is_array($open[$number]->data['raw'] ?? null) ? $open[$number]->data['raw'] : []);
                foreach ($known as $header) {
                    $raw[$header] = CellParser::neutralise(CellParser::text($cells[array_search($header, $sheet['headers'], true)] ?? null));
                }
                $changed[$number] = $raw;
            }

            if ($unknown !== []) {
                throw $refuse(__('These rows aren’t waiting to be imported in this batch: :rows. Re-upload only rows from its errors file.', [
                    'rows' => implode(', ', array_map(fn (string $r): string => mb_substr($r, 0, 10), array_slice($unknown, 0, 5))),
                ]));
            }
            if ($changed === []) {
                throw $refuse(__('The file has no rows.'));
            }

            $this->rechecker->apply($batch, $changed);

            $stored = 'imports/'.$batch->id.'/fixes-'.now()->format('YmdHis').'.'.$type.'.enc';
            $this->vault->seal((string) $file->getRealPath(), $stored);
            $batch->forceFill(['fix_sheet_path' => $stored])->save();

            $this->audit->record('broker.import_fixes_uploaded', $batch, after: ['rows' => count($changed)], actor: $actor, subjectLabel: $batch->code);

            return count($changed);
        });

        return $replaced;
    }
}
