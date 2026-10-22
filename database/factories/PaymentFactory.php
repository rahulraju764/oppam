<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A captured payment for the full amount of its order. State: failed().
 *
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'gateway_payment_id' => 'pay_'.Str::random(14),
            'amount_paise' => fn (array $attributes): int => (int) Order::query()->whereKey($attributes['order_id'])->value('total_paise'),
            'currency' => 'INR',
            'method' => 'upi',
            'status' => PaymentStatus::Captured,
            'captured_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentStatus::Failed, 'captured_at' => null, 'error_code' => 'BAD_REQUEST_ERROR']);
    }
}
