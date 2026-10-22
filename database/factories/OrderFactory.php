<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open (CREATED) fake-gateway order for a 1-month Gold price: ₹999 + 18 % GST = ₹1,178.82.
 * States: forPrice($price) (amounts from that price row), paid(), expired().
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'ORD'.fake()->unique()->numberBetween(900000, 999999),
            'profile_id' => Profile::factory(),
            // The seeded Gold plan and its 1-month price when they exist (plan codes are unique).
            'plan_id' => fn (): string => (string) (Plan::query()->where('code', 'GOLD')->value('id') ?? Plan::factory()->create()->id),
            'plan_price_id' => fn (array $attributes): string => (string) (PlanPrice::query()->where('plan_id', $attributes['plan_id'])->where('duration_months', 1)->value('id')
                ?? PlanPrice::factory()->create(['plan_id' => $attributes['plan_id']])->id),
            'duration_months' => 1,
            'coupon_id' => null,
            'subtotal_paise' => 99_900,
            'discount_paise' => 0,
            'taxable_paise' => 99_900,
            'gst_rate_bps' => 1800,
            'gst_paise' => 17_982,
            'total_paise' => 117_882,
            'currency' => 'INR',
            'status' => OrderStatus::Created,
            'gateway' => 'fake',
            'gateway_order_id' => 'order_'.Str::random(14),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function forPrice(PlanPrice $price, int $gstRateBps = 1800): static
    {
        $gst = intdiv($price->price_paise * $gstRateBps + 5_000, 10_000);

        return $this->state([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'duration_months' => $price->duration_months,
            'subtotal_paise' => $price->price_paise,
            'taxable_paise' => $price->price_paise,
            'gst_rate_bps' => $gstRateBps,
            'gst_paise' => $gst,
            'total_paise' => $price->price_paise + $gst,
        ]);
    }

    public function paid(): static
    {
        return $this->state(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['status' => OrderStatus::Created, 'expires_at' => now()->subMinute()]);
    }
}
