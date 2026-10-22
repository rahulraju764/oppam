<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One gateway payment against an order (M10). Written only from a signature-verified webhook or a
 * server-side gateway look-up; the UNIQUE gateway_payment_id makes a replay harmless.
 *
 * @property string $id
 * @property string $order_id
 * @property string $gateway_payment_id
 * @property int $amount_paise
 * @property string $currency
 * @property string|null $method
 * @property PaymentStatus $status
 * @property string|null $error_code
 * @property Carbon|null $captured_at
 * @property Carbon $created_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_paise' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
