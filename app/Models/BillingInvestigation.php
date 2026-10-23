<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvestigationStatus;
use App\Enums\InvestigationType;
use Database\Factories\BillingInvestigationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An A06 "investigate" row (P5.3): something the daily reconciliation or a refund failure found
 * that a person must settle — open until finance resolves it with a note (audited). `details`
 * holds amounts and codes only. `fingerprint` (type + gateway id) keeps re-runs from repeating it.
 *
 * @property string $id
 * @property InvestigationType $type
 * @property InvestigationStatus $status
 * @property string $fingerprint
 * @property string|null $order_id
 * @property string|null $refund_id
 * @property string|null $gateway_payment_id
 * @property array<string, int|string|null> $details
 * @property string|null $resolved_by_id
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read Refund|null $refund
 * @property-read AdminUser|null $resolvedBy
 */
final class BillingInvestigation extends Model
{
    /** @use HasFactory<BillingInvestigationFactory> */
    use HasFactory, HasUlids;

    public const QUEUE_KEY = 'billing_investigations';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InvestigationType::class,
            'status' => InvestigationStatus::class,
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', InvestigationStatus::Open);
    }

    /**
     * Open a row once per fingerprint (a re-run, or the webhook and the job finding the same thing,
     * adds nothing). Returns whether a new row was written.
     *
     * @param  array<string, int|string|null>  $details
     */
    public static function raise(InvestigationType $type, string $key, array $details, ?string $orderId = null, ?string $refundId = null, ?string $gatewayPaymentId = null): bool
    {
        return self::query()->insertOrIgnore([
            'id' => (string) str()->ulid(),
            'type' => $type->value,
            'status' => InvestigationStatus::Open->value,
            'fingerprint' => substr($type->value.':'.$key, 0, 100),
            'order_id' => $orderId,
            'refund_id' => $refundId,
            'gateway_payment_id' => $gatewayPaymentId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_id');
    }
}
