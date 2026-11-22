<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signature-valid payment webhook, stored raw (A06 shows the payloads) and unique per gateway
 * event id, so a redelivery is recognised. `outcome` records what processing did with it.
 *
 * @property string $id
 * @property string $gateway
 * @property string $event_id
 * @property string $event
 * @property string $payload
 * @property string|null $gateway_order_id
 * @property Carbon|null $processed_at
 * @property string|null $outcome
 * @property int $failed_attempts processing attempts that threw (P9.2b2)
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error exception class only — never its message
 * @property Carbon|null $replayed_at
 * @property string|null $replayed_by admin who last replayed it (A12)
 */
final class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'failed_attempts' => 'integer',
            'last_failed_at' => 'datetime',
            'replayed_at' => 'datetime',
        ];
    }
}
