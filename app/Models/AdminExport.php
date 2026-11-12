<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminExportStatus;
use App\Enums\ExportFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A large A09 report export built in the background (P9.1a): private disk, its admin's only,
 * downloadable for 24 hours. Every field is server-written (forceFill).
 *
 * @property string $id
 * @property string $code
 * @property string $admin_user_id
 * @property string $report
 * @property array{from: string, to: string} $params
 * @property ExportFormat $format
 * @property AdminExportStatus $status
 * @property string|null $path
 * @property int|null $rows
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AdminExport extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'format' => ExportFormat::class,
            'status' => AdminExportStatus::class,
            'rows' => 'integer',
            'ready_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function fileName(): string
    {
        return $this->report.'-'.$this->params['from'].'-to-'.$this->params['to'].'.'.$this->format->value;
    }
}
