<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SystemAlertKind;
use Database\Factories\SystemAlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A12 alert (P9.2b2): a threshold CheckSystemHealth saw crossed, open until the condition has been
 * clear for A15 `system.alert_clear_minutes`. Written only by SyncSystemAlerts.
 *
 * @property string $id
 * @property SystemAlertKind $kind
 * @property string|null $open_key the kind while open (unique), null once resolved
 * @property int $value
 * @property int $threshold
 * @property Carbon $opened_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $clear_since
 * @property Carbon|null $resolved_at
 * @property Carbon|null $notified_at
 */
final class SystemAlert extends Model
{
    /** @use HasFactory<SystemAlertFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    public function describe(): string
    {
        return $this->kind->describe($this->value, $this->threshold);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SystemAlertKind::class,
            'value' => 'integer',
            'threshold' => 'integer',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'clear_since' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
