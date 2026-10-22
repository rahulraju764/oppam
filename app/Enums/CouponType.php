<?php

declare(strict_types=1);

namespace App\Enums;

/** How a coupon takes money off the pre-GST plan price (A06 "percent / fixed"). */
enum CouponType: string
{
    case Percent = 'PERCENT';
    case Fixed = 'FIXED';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percent off'),
            self::Fixed => __('Amount off'),
        };
    }
}
