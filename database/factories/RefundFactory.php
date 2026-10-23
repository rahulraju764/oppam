<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'RFD'.fake()->unique()->numberBetween(900000, 999999),
            'order_id' => Order::factory()->paid(),
            'payment_id' => fn (array $attributes): string => (string) (Payment::query()->where('order_id', $attributes['order_id'])->value('id')
                ?? Payment::factory()->create(['order_id' => $attributes['order_id']])->id),
            'profile_id' => fn (array $attributes): string => (string) Order::query()->whereKey($attributes['order_id'])->value('profile_id'),
            'category' => RefundCategory::Goodwill,
            'amount_paise' => fn (array $attributes): int => (int) Payment::query()->whereKey($attributes['payment_id'])->value('amount_paise'),
            'ends_plan' => true,
            'reason' => 'Member asked within a day of buying',
            'status' => RefundStatus::Requested,
            'requested_by_id' => AdminUser::factory(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Processed,
            'decided_by_id' => AdminUser::factory(),
            'decided_at' => now(),
            'gateway_refund_id' => 'rfnd_'.fake()->unique()->bothify('##############'),
            'effects_applied_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
