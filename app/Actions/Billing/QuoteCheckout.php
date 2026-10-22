<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\PriceQuoteData;
use App\Domain\Billing\CouponEligibility;
use App\Domain\Billing\PlanChangeRule;
use App\Domain\Billing\PriceCalculator;
use App\Enums\OrderStatus;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Billing\CurrentMembership;
use App\Queries\Billing\PlanCatalog;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a checkout would charge (M10, F04 "amount from plan key + coupon + GST"): the active price
 * row of the plan for the duration, the coupon if it may be used, GST from A15
 * (`billing.gst_rate_bps`), and how the purchase changes the member's current plan. Reads only —
 * it is the single place the amount is computed, for the page and again for the order.
 */
final class QuoteCheckout
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly CurrentMembership $membership,
        private readonly PlanChangeRule $planChange,
        private readonly CouponEligibility $coupons,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * @throws NotFoundHttpException the plan / duration isn't on sale, or the member has no profile
     * @throws ValidationException the coupon can't be used (key `coupon`)
     */
    public function handle(User $member, PlanCode $code, int $duration, ?string $couponCode = null): PriceQuoteData
    {
        $profile = $member->profile ?? throw new NotFoundHttpException;
        [$plan, $price] = $this->catalog->purchasable($code, $duration) ?? throw new NotFoundHttpException;

        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = Coupon::query()->where('code', Coupon::normalise($couponCode))->first();
            $problem = $this->coupons->problem(
                $coupon,
                $code,
                $price->price_paise,
                $coupon === null ? 0 : CouponRedemption::query()->where('coupon_id', $coupon->id)->where('profile_id', $profile->id)->count(),
                $this->hasPaidBefore($profile),
                CarbonImmutable::now(),
            );

            if ($problem !== null) {
                throw ValidationException::withMessages(['coupon' => $problem->message()]);
            }
        }

        $now = CarbonImmutable::now();
        $current = $this->membership->current($profile, $now);
        $change = $this->planChange->decide($current?->plan, $plan);
        [$startsAt, $endsAt] = $this->membership->period($profile, $plan->id, $change, $duration, $now);

        return new PriceQuoteData(
            plan: $code,
            planId: $plan->id,
            planName: $plan->name,
            durationMonths: $duration,
            planPriceId: $price->id,
            price: $this->calculator->quote($price->price_paise, $coupon, Settings::int(SettingKey::BillingGstRateBps)),
            couponId: $coupon?->id,
            couponCode: $coupon?->code,
            change: $change,
            startsAt: $startsAt,
            endsAt: $endsAt,
            currentPlanName: $current?->plan->name,
            currentEndsAt: $current === null ? null : CarbonImmutable::instance($current->ends_at),
        );
    }

    private function hasPaidBefore(Profile $profile): bool
    {
        return Order::query()->where('profile_id', $profile->id)->where('status', OrderStatus::Paid->value)->exists();
    }
}
