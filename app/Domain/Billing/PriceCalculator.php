<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Data\Billing\PriceBreakdown;
use App\Enums\CouponType;
use App\Models\Coupon;
use InvalidArgumentException;

/**
 * The amounts of a checkout (M10, F04: "amount from plan key + coupon + GST"). Pure integer maths in
 * paise — never floats:
 *  - discount: percent of the plan price rounded half up (capped by max_discount_paise), or a fixed
 *    amount; never so much that less than ₹1 remains (the gateway's minimum);
 *  - GST on the price after the discount, rounded half up to the paisa;
 *  - total = taxable + GST.
 * The caller has already decided the coupon may be used (CouponEligibility).
 */
final class PriceCalculator
{
    public const MIN_TAXABLE_PAISE = 100;

    public function quote(int $pricePaise, ?Coupon $coupon, int $gstRateBps): PriceBreakdown
    {
        if ($pricePaise < self::MIN_TAXABLE_PAISE) {
            throw new InvalidArgumentException('A plan price must be at least ₹1.');
        }

        if ($gstRateBps < 0) {
            throw new InvalidArgumentException('The GST rate cannot be negative.');
        }

        $discount = $coupon === null ? 0 : $this->discount($pricePaise, $coupon);
        $taxable = $pricePaise - $discount;
        $gst = intdiv($taxable * $gstRateBps + 5_000, 10_000);

        return new PriceBreakdown(
            subtotalPaise: $pricePaise,
            discountPaise: $discount,
            taxablePaise: $taxable,
            gstRateBps: $gstRateBps,
            gstPaise: $gst,
            totalPaise: $taxable + $gst,
        );
    }

    private function discount(int $pricePaise, Coupon $coupon): int
    {
        $discount = match ($coupon->type) {
            CouponType::Percent => intdiv($pricePaise * min(100, max(0, $coupon->value)) + 50, 100),
            CouponType::Fixed => max(0, $coupon->value),
        };

        if ($coupon->type === CouponType::Percent && $coupon->max_discount_paise !== null) {
            $discount = min($discount, $coupon->max_discount_paise);
        }

        return min($discount, $pricePaise - self::MIN_TAXABLE_PAISE);
    }
}
