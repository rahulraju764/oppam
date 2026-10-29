<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Domain\Broker\Import\CellParser;
use App\Domain\Broker\Import\ImportBatchAccess;
use App\Domain\Broker\Import\ImportRowRechecker;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Fix cells inline" (§11A B.9 step 3): one cell of a not-yet-imported row of a READY batch gets a
 * new value and the row is checked again by the same rules as the single form (R-M13-28) — rows
 * sharing its old or new mobile too. The column must be one of the sheet's own headers; the value
 * is stored neutralised like every cell (B.18). Audited without the value (it may be personal data).
 */
final class FixImportRow
{
    public const MAX_LENGTH = 500;

    private const PER_MINUTE = 120;

    public function __construct(
        private readonly ImportBatchAccess $access,
        private readonly ImportRowRechecker $rechecker,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `profiles.import`
     * @throws ModelNotFoundException<BrokerImportBatch|BrokerImportRow>
     * @throws ImportRefused flag off, paused, not READY
     * @throws ValidationException key `value`
     */
    public function handle(User $actor, string $batchCode, int $rowNumber, string $header, string $value): BrokerImportRow
    {
        $staff = $this->access->staff($actor);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['value' => __('Keep it under :max characters.', ['max' => self::MAX_LENGTH])]);
        }

        $key = 'broker-import-fix:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages(['value' => __('Too many changes at once. Please wait a moment.')]);
        }
        $this->limiter->hit($key, 60);

        return DB::transaction(function () use ($actor, $staff, $batchCode, $rowNumber, $header, $value): BrokerImportRow {
            $batch = $this->access->find($staff, $batchCode, lock: true);
            if ($batch->status !== ImportBatchStatus::Ready) {
                throw ImportRefused::wrongStatus();
            }
            if (! in_array($header, $batch->headers ?? [], true)) {
                throw ValidationException::withMessages(['value' => __('That column is not in this file.')]);
            }

            $row = BrokerImportRow::query()->where('batch_id', $batch->id)->where('row_number', $rowNumber)
                ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning, ImportRowStatus::Error])
                ->firstOrFail();

            $raw = is_array($row->data['raw'] ?? null) ? $row->data['raw'] : [];
            $raw[$header] = CellParser::neutralise(trim($value));

            $this->rechecker->apply($batch, [$rowNumber => $raw]);

            $this->audit->record('broker.import_row_fixed', $batch, after: ['row' => $rowNumber, 'column' => $header], actor: $actor, subjectLabel: $batch->code);

            return $row->refresh();
        });
    }
}
