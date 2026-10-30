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
 * @property string|null $payout_item_id the payout line that paid it (kept after a void: the next run claws it back)
 * @property string|null $clawed_back_item_id the payout line that took a voided, already-paid commission back (B.19)
 * @property Carbon|null $earned_at
 * @property Carbon|null $voided_at
 * @property ReferralVoidReason|null $void_reason
 * @property string|null $signup_ip_hash HMAC of the referred member's sign-up IP (never the IP) — B.12 signals
 * @property string|null $signup_net_hash HMAC of its /24 (/48) network
 * @property string|null $signup_device_hash sha256 of the sign-up device cookie (as login_events)
 * @property Carbon $created_at
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 * @property-read Order|null $order
 * @property-read BrokerPayoutItem|null $payoutItem
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

    /** @return BelongsTo<BrokerPayoutItem, $this> */
    public function payoutItem(): BelongsTo
    {
        return $this->belongsTo(BrokerPayoutItem::class, 'payout_item_id');
    }
}
