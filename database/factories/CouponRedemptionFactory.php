<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A coupon used on a paid order of the same member.
 *
 * @extends Factory<CouponRedemption>
 */
final class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'order_id' => Order::factory()->paid(),
            'profile_id' => fn (array $attributes): string => (string) Order::query()->whereKey($attributes['order_id'])->value('profile_id'),
            'discount_paise' => 9_990,
        ];
    }
}
