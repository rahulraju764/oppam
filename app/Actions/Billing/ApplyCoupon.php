<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\PriceQuoteData;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A member types a coupon code at checkout (M10 "coupon code, validated live"). Every try counts
 * against `billing.coupon_attempts_per_hour` (rule 10 — codes can't be guessed by trying many);
 * an unknown, inactive or used-up code reads the same as any invalid one (CouponProblem).
 */
final class ApplyCoupon
{
    public function __construct(
        private readonly QuoteCheckout $quote,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws ValidationException key `coupon`: invalid, or too many tries */
    public function handle(User $member, PlanCode $code, int $duration, string $couponCode): PriceQuoteData
    {
        $key = 'coupon-attempts:'.$member->id;
        if ($this->limiter->hit($key, 3600) > Settings::int(SettingKey::BillingCouponAttemptsPerHour)) {
            throw ValidationException::withMessages(['coupon' => __('Too many codes tried. Please wait a while and try again.')]);
        }

        return $this->quote->handle($member, $code, $duration, $couponCode);
    }
}
