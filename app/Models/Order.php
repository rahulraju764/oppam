<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\ValueObjects\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One checkout attempt (M10, F04). Every amount is computed on the server (PriceCalculator) from
 * the plan price row, the coupon and the GST rate, and frozen here when the order is created —
 * nothing on it ever comes from the browser, so nothing is mass-assignable. `code` (ORD100001) is
 * the only identifier in URLs.
 *
 * @property string $id
 * @property string $code
 * @property string $profile_id
 * @property string $plan_id
 * @property string $plan_price_id
 * @property int $duration_months
 * @property string|null $coupon_id
 * @property int $subtotal_paise
 * @property int $discount_paise
 * @property int $taxable_paise
 * @property int $gst_rate_bps
 * @property int $gst_paise
 * @property int $total_paise
 * @property string $currency
 * @property OrderStatus $status
 * @property string $gateway
 * @property string|null $gateway_order_id
 * @property string|null $failure_reason
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property string|null $paid_payment_id the captured payment that activated it (A06 refunds)
 * @property Carbon $created_at
 */
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'duration_months' => 'integer',
            'subtotal_paise' => 'integer',
            'discount_paise' => 'integer',
            'taxable_paise' => 'integer',
            'gst_rate_bps' => 'integer',
            'gst_paise' => 'integer',
            'total_paise' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function total(): Money
    {
        return Money::paise($this->total_paise);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function paidPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'paid_payment_id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
