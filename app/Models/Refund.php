<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\ValueObjects\Money;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A refund of one captured payment (A06, P5.3), full or partial. Asked for by one admin and decided
 * by another (decisions 2026-10-23); moved through its states only by the Admin\Billing refund
 * Actions and the gateway's webhook — nothing is mass-assignable. `ends_plan`: this refund returns
 * the rest of the payment that activated the order, so the plan ends with it.
 *
 * @property string $id
 * @property string $code
 * @property string $order_id
 * @property string $payment_id
 * @property string $profile_id
 * @property RefundCategory $category
 * @property int $amount_paise
 * @property bool $ends_plan
 * @property string $reason
 * @property RefundStatus $status
 * @property string $requested_by_id
 * @property string|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property string|null $gateway_refund_id
 * @property string|null $failure_reason
 * @property Carbon|null $effects_applied_at
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read Payment $payment
 * @property-read AdminUser $requestedBy
 * @property-read AdminUser|null $decidedBy
 * @property-read CreditNote|null $creditNote
 */
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => RefundCategory::class,
            'status' => RefundStatus::class,
            'amount_paise' => 'integer',
            'ends_plan' => 'boolean',
            'decided_at' => 'datetime',
            'effects_applied_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::paise($this->amount_paise);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_id');
    }

    /** @return HasOne<CreditNote, $this> */
    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }
}
