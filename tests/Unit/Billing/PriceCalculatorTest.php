<?php

declare(strict_types=1);

use App\Domain\Billing\PriceCalculator;
use App\Enums\CouponType;
use App\Models\Coupon;

/*
| P5.1 — checkout amounts (PRD M10 / F04 "amount from plan key + coupon + GST"): integer paise only,
| GST on the price after the discount, half-up rounding, never less than ₹1 to pay.
*/

function couponOf(CouponType $type, int $value, ?int $cap = null): Coupon
{
    return (new Coupon)->forceFill(['type' => $type, 'value' => $value, 'max_discount_paise' => $cap]);
}

it('M10: adds 18 % GST to the plan price, rounded half up to the paisa', function (int $price, int $gst, int $total): void {
    $quote = (new PriceCalculator)->quote($price, null, 1800);

    expect($quote->subtotalPaise)->toBe($price)
        ->and($quote->discountPaise)->toBe(0)
        ->and($quote->taxablePaise)->toBe($price)
        ->and($quote->gstPaise)->toBe($gst)
        ->and($quote->totalPaise)->toBe($total);
})->with([
    'Silver ₹499' => [49_900, 8_982, 58_882],
    'Gold ₹999' => [99_900, 17_982, 117_882],
    'Diamond ₹1,999' => [199_900, 35_982, 235_882],
    'Gold 12 months ₹8,991' => [899_100, 161_838, 1_060_938],
]);

it('rounds GST half up: 0.5 paisa → 1, 0.49 → 0', function (): void {
    // ₹1.25 × 18 % = 22.5 paise → 23; ₹1.27 × 18 % = 22.86 → 23; ₹1.02 × 18 % = 18.36 → 18
    expect((new PriceCalculator)->quote(125, null, 1800)->gstPaise)->toBe(23)
        ->and((new PriceCalculator)->quote(127, null, 1800)->gstPaise)->toBe(23)
        ->and((new PriceCalculator)->quote(102, null, 1800)->gstPaise)->toBe(18);
});

it('A06 coupons: percent (half up, capped) or fixed off the pre-GST price; GST on what remains', function (): void {
    $calculator = new PriceCalculator;

    $percent = $calculator->quote(99_900, couponOf(CouponType::Percent, 10), 1800);
    expect($percent->discountPaise)->toBe(9_990)
        ->and($percent->taxablePaise)->toBe(89_910)
        ->and($percent->gstPaise)->toBe(16_184)          // 89,910 × 0.18 = 16,183.8
        ->and($percent->totalPaise)->toBe(106_094);

    expect($calculator->quote(99_900, couponOf(CouponType::Percent, 50, cap: 20_000), 1800)->discountPaise)->toBe(20_000)
        ->and($calculator->quote(99_900, couponOf(CouponType::Fixed, 20_000), 1800)->taxablePaise)->toBe(79_900);
});

it('never discounts below ₹1 payable, and refuses a price under ₹1', function (): void {
    $calculator = new PriceCalculator;

    expect($calculator->quote(49_900, couponOf(CouponType::Fixed, 1_000_000), 1800)->taxablePaise)->toBe(100)
        ->and($calculator->quote(49_900, couponOf(CouponType::Percent, 100), 1800)->taxablePaise)->toBe(100);

    $calculator->quote(99, null, 1800);
})->throws(InvalidArgumentException::class);

it('shows the GST rate from basis points', function (): void {
    expect((new PriceCalculator)->quote(10_000, null, 1800)->gstRateLabel())->toBe('18')
        ->and((new PriceCalculator)->quote(10_000, null, 1250)->gstRateLabel())->toBe('12.5');
});
