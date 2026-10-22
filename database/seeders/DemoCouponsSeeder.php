<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Seeder;

/**
 * Demo coupons for trying checkout locally (LOCAL ONLY — DatabaseSeeder). Real coupons are
 * created by admins in A06 (P5.3). Insert-only.
 */
final class DemoCouponsSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            ['code' => 'WELCOME10', 'type' => CouponType::Percent, 'value' => 10, 'max_discount_paise' => 50_000, 'first_time_only' => true],
            ['code' => 'FLAT200', 'type' => CouponType::Fixed, 'value' => 20_000, 'min_order_paise' => 100_000],
        ];

        foreach ($coupons as $definition) {
            if (Coupon::query()->where('code', $definition['code'])->exists()) {
                continue;
            }

            (new Coupon)->forceFill([...$definition, 'per_member_limit' => 1, 'is_active' => true])->save();
        }
    }
}
