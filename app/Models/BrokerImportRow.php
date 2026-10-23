<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportRowStatus;
use Database\Factories\BrokerImportRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an import batch (B.9). `imported_profile_id` makes the import idempotent (R-M13-33). Nothing is mass-assignable.
 *
 * @property int $id
 * @property string $batch_id
 * @property int $row_number
 * @property array<string, mixed> $data
 * @property ImportRowStatus $status
 * @property list<array<string, string>>|null $messages
 * @property string|null $imported_profile_id
 * @property-read BrokerImportBatch|null $batch
 */
final class BrokerImportRow extends Model
{
    /** @use HasFactory<BrokerImportRowFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'messages' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    /** @return BelongsTo<BrokerImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BrokerImportBatch::class, 'batch_id');
    }
}
