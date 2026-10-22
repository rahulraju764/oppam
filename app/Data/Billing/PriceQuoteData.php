<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\PlanChange;
use App\Enums\PlanCode;
use Carbon\CarbonImmutable;

/**
 * What a checkout would charge and give (QuoteCheckout): the plan and duration, the server-computed
 * amounts, the coupon that applies, and how it changes the member's current plan. Shown on the
 * checkout page; CreateOrder computes it again rather than trusting anything the page holds.
 */
final readonly class PriceQuoteData
{
    public function __construct(
        public PlanCode $plan,
        public string $planId,
        public string $planName,
        public int $durationMonths,
        public string $planPriceId,
        public PriceBreakdown $price,
        public ?string $couponId,
        public ?string $couponCode,
        public PlanChange $change,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $currentPlanName,
        public ?CarbonImmutable $currentEndsAt,
    ) {}
}
