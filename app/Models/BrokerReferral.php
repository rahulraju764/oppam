<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\ValueObjects\Money;
use Database\Factories\BrokerReferralFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A row of a bureau's commission ledger — referral or managed-profile conversion (B.5 / B.10),
 * one per profile. PENDING when attributed (ResolveBrokerCode, or the A07 link), EARNED by
 * ReferralCommission from the profile's first paid order, VOID on a refund (R-M13-5), PAID by a
 * payout run (P7.8). Base and rate are snapshots taken when earned. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $profile_id
 * @property ReferralSource $source
 * @property string|null $order_id
 * @property int|null $base_paise
 * @property int|null $commission_bps
 * @property int $commission_paise
 * @property ReferralStatus $status
 * @property string|null $payout_item_id
 * @property Carbon|null $earned_at
 * @property Carbon|null $voided_at
 * @property ReferralVoidReason|null $void_reason
 * @property Carbon $created_at
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 * @property-read Order|null $order
 */
final class BrokerReferral extends Model
{
    /** @use HasFactory<BrokerReferralFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ReferralSource::class,
            'status' => ReferralStatus::class,
            'void_reason' => ReferralVoidReason::class,
            'base_paise' => 'integer',
            'commission_bps' => 'integer',
            'commission_paise' => 'integer',
            'earned_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function commission(): Money
    {
        return Money::paise($this->commission_paise);
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
