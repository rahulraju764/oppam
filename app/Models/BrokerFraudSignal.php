<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrokerSignal;
use Database\Factories\BrokerFraudSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fraud / quality signal of a bureau (§11A B.12). OPEN while `resolved_at` is null — one OPEN
 * row per (bureau, type), guarded by the unique `open_key`; the daily scan refreshes its details and
 * `last_seen_at`. An admin resolves it with a note. `details` hold codes and counts only — never
 * names, phones or IPs. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property BrokerSignal $type
 * @property array<string, mixed>|null $details
 * @property string|null $open_key
 * @property Carbon $detected_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by_admin_id
 * @property string|null $resolution_note
 * @property-read Broker|null $broker
 * @property-read AdminUser|null $resolvedBy
 */
final class BrokerFraudSignal extends Model
{
    /** @use HasFactory<BrokerFraudSignalFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => BrokerSignal::class,
            'details' => 'array',
            'detected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function openKey(string $brokerId, BrokerSignal $type): string
    {
        return $brokerId.':'.$type->value;
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }
}
