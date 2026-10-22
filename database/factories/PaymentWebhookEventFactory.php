<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A processed payment.captured delivery.
 *
 * @extends Factory<PaymentWebhookEvent>
 */
final class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'gateway' => 'fake',
            'event_id' => 'evt_'.Str::random(14),
            'event' => 'payment.captured',
            'payload' => '{"event":"payment.captured"}',
            'gateway_order_id' => 'order_'.Str::random(14),
            'processed_at' => now(),
            'outcome' => 'activated',
        ];
    }
}
