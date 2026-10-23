<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Database\Factories\BrokerImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property array<string, mixed>|null $column_map
 * @property ImportBatchStatus $status
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_warning
 * @property int $rows_error
 * @property int $rows_imported
 * @property Carbon|null $consent_attested_at
 * @property string|null $consent_attested_by_user_id
 * @property Carbon|null $files_purged_at
 * @property-read Broker|null $broker
 */
final class BrokerImportBatch extends Model
{
    /** @use HasFactory<BrokerImportBatchFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $hidden = ['sheet_path', 'photos_zip_path'];

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'status' => ImportBatchStatus::class,
            'consent_attested_at' => 'datetime',
            'files_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}
