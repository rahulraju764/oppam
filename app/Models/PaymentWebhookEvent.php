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
        return ['processed_at' => 'datetime'];
    }
}
