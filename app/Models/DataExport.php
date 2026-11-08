<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Download my data" (M14, P8.2c): one request. While READY and until expires_at the zip is on the
 * private disk at `file_path`; URLs use `code` (DEX…), never the id, and no link carries a token —
 * the download is a signed-in, owner-scoped request.
 *
 * @property string $id
 * @property string $code
 * @property string $user_id
 * @property DataExportStatus $status
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $downloaded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'file_size_bytes' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'downloaded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Ready && $this->file_path !== null
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->status === DataExportStatus::Expired
            || ($this->status === DataExportStatus::Ready && $this->expires_at !== null && ! $this->expires_at->isFuture());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
