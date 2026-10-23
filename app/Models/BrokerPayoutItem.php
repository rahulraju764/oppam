<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutItemStatus;
use Database\Factories\BrokerPayoutItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bureau's line in a payout run, in paise (A07; P7.8). Balances under the minimum roll forward (R-A07-5). Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $run_id
 * @property string $broker_id
 * @property int $gross_paise
 * @property int $clawback_paise
 * @property int $carried_forward_paise
 * @property int $net_paise
 * @property string|null $utr
 * @property PayoutItemStatus $status
 * @property Carbon|null $paid_at
 * @property-read BrokerPayoutRun|null $run
 * @property-read Broker|null $broker
 */
final class BrokerPayoutItem extends Model
{
    /** @use HasFactory<BrokerPayoutItemFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_paise' => 'integer',
            'clawback_paise' => 'integer',
            'carried_forward_paise' => 'integer',
            'net_paise' => 'integer',
            'status' => PayoutItemStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BrokerPayoutRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BrokerPayoutRun::class, 'run_id');
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}
