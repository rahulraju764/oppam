<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active 10 %-off coupon, once per member, any plan. States: fixed($paise), percent($pct).
 *
 * @extends Factory<Coupon>
 */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'SAVE'.fake()->unique()->numberBetween(1000, 9999),
            'type' => CouponType::Percent,
            'value' => 10,
            'max_discount_paise' => null,
            'min_order_paise' => null,
            'max_redemptions' => null,
            'per_member_limit' => 1,
            'first_time_only' => false,
            'plan_codes' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
            'redemptions_count' => 0,
        ];
    }

    public function fixed(int $paise): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $paise]);
    }

    public function percent(int $percent): static
    {
        return $this->state(['type' => CouponType::Percent, 'value' => $percent]);
    }
}
