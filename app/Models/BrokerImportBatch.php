<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportStopReason;
use Database\Factories\BrokerImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A bulk-import upload (B.9; P7.6–P7.7). Files are private and purged at 30 days. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $code
 * @property string $broker_id
 * @property string $uploaded_by_user_id
 * @property string|null $sheet_path
 * @property string|null $photos_zip_path
 * @property string $file_sha256
 * @property array<string, mixed>|null $column_map field => the sheet header it reads from (P7.6)
 * @property list<string>|null $headers the sheet's header row as uploaded (P7.6)
 * @property string|null $duplicate_of_batch_id this bureau uploaded the same file before (P7.6 banner)
 * @property string|null $error_message why the batch FAILED — safe to show, never cell contents
 * @property ImportBatchStatus $status
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_warning
 * @property int $rows_error
 * @property int $rows_imported
 * @property int $rows_skipped ERROR rows left when the batch completed, or rows stopped by a lost uploader (P7.7)
 * @property int $rows_submitted imported profiles sent to A04 (P7.7)
 * @property int $rows_approved A04 approvals of this batch's profiles — approval rate, auto-pause (R-M13-32)
 * @property int $rows_rejected A04 rejections of this batch's profiles
 * @property string|null $fix_sheet_path the re-uploaded errors file (private, encrypted)
 * @property list<string>|null $zip_names the photo zip's file names, lowercase (null = no zip) — for re-checks
 * @property Carbon|null $consent_attested_at
 * @property string|null $consent_attested_by_user_id
 * @property Carbon|null $import_started_at
 * @property ImportStopReason|null $stop_reason why the last import run stopped early (P7.7)
 * @property Carbon|null $files_purged_at
 * @property array<string, int>|null $signal_flags P7.8c B.12 batch flags: mobile_pattern / photo_matches => rows (ImportBatchSignals)
 * @property Carbon $created_at
 * @property-read Broker|null $broker
 */
final class BrokerImportBatch extends Model
{
    /** @use HasFactory<BrokerImportBatchFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $hidden = ['sheet_path', 'photos_zip_path', 'fix_sheet_path'];

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'headers' => 'array',
            'zip_names' => 'array',
            'status' => ImportBatchStatus::class,
            'consent_attested_at' => 'datetime',
            'import_started_at' => 'datetime',
            'stop_reason' => ImportStopReason::class,
            'signal_flags' => 'array',
            'files_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return HasOne<BrokerStaff, $this> the uploader's bureau login (history: "uploaded by") */
    public function uploaderStaff(): HasOne
    {
        return $this->hasOne(BrokerStaff::class, 'user_id', 'uploaded_by_user_id');
    }

    /** The sheet's type while its file is kept (xlsx / csv), or null once purged. */
    public function sheetType(): ?string
    {
        return $this->sheet_path !== null && preg_match('/\.(xlsx|csv)\.enc$/', $this->sheet_path, $m) === 1 ? $m[1] : null;
    }

    /**
     * Can still be cancelled (B.9 "cancel before import"; a batch already partly imported — stopped
     * by the daily cap — is closed instead: its remaining rows are skipped). Not while
     * the validation job is working on it (it would write the batch back) — a queued UPLOADED batch
     * is fine: the job skips a cancelled batch.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, [ImportBatchStatus::Uploaded, ImportBatchStatus::Mapping, ImportBatchStatus::Ready, ImportBatchStatus::Failed], true);
    }

    /** Approved share of this batch's decided A04 reviews, 0–100, or null before any decision. */
    public function approvalRate(): ?int
    {
        $decided = $this->rows_approved + $this->rows_rejected;

        return $decided === 0 ? null : (int) round($this->rows_approved * 100 / $decided);
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return HasMany<BrokerImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BrokerImportRow::class, 'batch_id');
    }
}
