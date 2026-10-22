<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\CouponProblem;
use App\Enums\PlanCode;
use App\Models\Coupon;
use Carbon\CarbonInterface;

/**
 * May this coupon be used on this checkout? (A06 coupon rules.) Pure: the caller supplies the
 * member's history. Checked when the price is shown, when the order is created and never again —
 * a coupon that expires while the member is paying still applies to the order they started.
 */
final class CouponEligibility
{
    public function problem(
        ?Coupon $coupon,
        PlanCode $plan,
        int $pricePaise,
        int $memberRedemptions,
        bool $hasPaidBefore,
        CarbonInterface $now,
    ): ?CouponProblem {
        if ($coupon === null || ! $coupon->is_active) {
            return CouponProblem::Invalid;
        }

        if ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions) {
            return CouponProblem::Invalid;
        }

        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            return CouponProblem::NotStarted;
        }

        if ($coupon->ends_at !== null && $now->gte($coupon->ends_at)) {
            return CouponProblem::Expired;
        }

        if ($coupon->plan_codes !== null && $coupon->plan_codes !== [] && ! in_array($plan->value, $coupon->plan_codes, true)) {
            return CouponProblem::WrongPlan;
        }

        if ($coupon->min_order_paise !== null && $pricePaise < $coupon->min_order_paise) {
            return CouponProblem::BelowMinimum;
        }

        if ($memberRedemptions >= $coupon->per_member_limit) {
            return CouponProblem::AlreadyUsed;
        }

        if ($coupon->first_time_only && $hasPaidBefore) {
            return CouponProblem::FirstOrderOnly;
        }

        return null;
    }
}
