<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a coupon can't be used on this checkout (CouponEligibility). An unknown, inactive or used-up
 * code all read "isn't valid", so trying codes tells nobody which exist.
 */
enum CouponProblem: string
{
    case Invalid = 'invalid';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case WrongPlan = 'wrong_plan';
    case BelowMinimum = 'below_minimum';
    case AlreadyUsed = 'already_used';
    case FirstOrderOnly = 'first_order_only';

    public function message(): string
    {
        return match ($this) {
            self::Invalid => __('This code isn’t valid.'),
            self::NotStarted => __('This code can’t be used yet.'),
            self::Expired => __('This code has expired.'),
            self::WrongPlan => __('This code can’t be used with this plan.'),
            self::BelowMinimum => __('This code needs a longer or higher plan.'),
            self::AlreadyUsed => __('You have already used this code.'),
            self::FirstOrderOnly => __('This code is for a first membership only.'),
        };
    }
}
