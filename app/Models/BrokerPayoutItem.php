<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutItemStatus;
use App\ValueObjects\Money;
use Database\Factories\BrokerPayoutItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bureau's line in a payout run, in paise (A07, §11A B.10; decisions 2026-10-29), worked out by
 * PayoutCalculator: gross (EARNED commissions) − clawback (commissions voided after they were paid)
 * + carried in (an earlier line's balance) − TDS = net. A line under the minimum is CARRIED_FORWARD
 * (R-A07-5): nothing is paid, `carried_forward_paise` holds the balance and the next run's line
 * takes it over (`rolled_into_item_id`). A PENDING line is paid with a UTR. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $run_id
 * @property string $broker_id
 * @property int $gross_paise
 * @property int $clawback_paise
 * @property int $carried_in_paise may be negative (a clawback larger than what was earned)
 * @property int $tds_paise
 * @property int $carried_forward_paise
 * @property string|null $rolled_into_item_id
 * @property int $net_paise
 * @property string|null $utr
 * @property PayoutItemStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read BrokerPayoutRun|null $run
 * @property-read Broker|null $broker
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BrokerReferral> $referrals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BrokerReferral> $clawbacks
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
            'carried_in_paise' => 'integer',
            'tds_paise' => 'integer',
            'carried_forward_paise' => 'integer',
            'net_paise' => 'integer',
            'status' => PayoutItemStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function net(): Money
    {
        return Money::paise(max(0, $this->net_paise));
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

    /**
     * The commissions this line pays (a row voided before payment stays here and is clawed back later).
     *
     * @return HasMany<BrokerReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(BrokerReferral::class, 'payout_item_id');
    }

    /**
     * Earlier-paid commissions this line takes back (B.19).
     *
     * @return HasMany<BrokerReferral, $this>
     */
    public function clawbacks(): HasMany
    {
        return $this->hasMany(BrokerReferral::class, 'clawed_back_item_id');
    }
}
