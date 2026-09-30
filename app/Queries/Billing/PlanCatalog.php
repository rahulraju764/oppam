<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Data\Billing\PlanCardData;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\ValueObjects\Money;

/**
 * The purchasable plans as pricing cards (M10 plans page, home teaser). Prices come from
 * plan_prices (monthly row) in paise; the "billed per year" line is 12 × monthly, as on the
 * template. A plan with no active monthly price is not shown.
 */
final class PlanCatalog
{
    /** @return list<PlanCardData> */
    public function cards(?string $ctaUrl): array
    {
        $plans = Plan::query()
            ->purchasable()
            ->with(['prices' => fn ($query) => $query->where('is_active', true)->where('duration_months', 1)])
            ->get();

        $cards = [];

        foreach ($plans as $plan) {
            /** @var PlanPrice|null $monthly */
            $monthly = $plan->prices->first();

            if ($monthly === null) {
                continue;
            }

            $cards[] = new PlanCardData(
                key: $plan->code->value,
                name: $plan->name,
                monthlyPrice: $monthly->price(),
                yearlyPrice: Money::paise($monthly->price_paise)->multiply(12),
                features: $plan->display_features,
                isFeatured: $plan->is_featured,
                badge: $plan->badge,
                ctaUrl: $ctaUrl,
            );
        }

        return $cards;
    }
}
