<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Data\Billing\PlanCardData;
use App\Data\Billing\PlanComparisonData;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * The purchasable plans (M10 plans page, home teaser, checkout). Prices come from plan_prices in
 * paise, one row per duration (1 / 3 / 6 / 12 months — decisions 2026-10-21); the saving on a
 * longer duration is measured against the monthly price × months. A plan without an active price
 * for the chosen duration is not offered.
 */
final class PlanCatalog
{
    public const DEFAULT_DURATION = 1;

    /** @var Collection<int, Plan>|null */
    private ?Collection $plans = null;

    /**
     * @param  bool  $toCheckout  the card's button opens checkout for that plan and duration (/plans);
     *                            otherwise it links to $ctaUrl (home teaser → /plans)
     * @return list<PlanCardData>
     */
    public function cards(?string $ctaUrl, int $duration = self::DEFAULT_DURATION, bool $toCheckout = false): array
    {
        $cards = [];

        foreach ($this->plans() as $plan) {
            $prices = $plan->prices->keyBy('duration_months');
            /** @var PlanPrice|null $price */
            $price = $prices->get($duration);
            if ($price === null) {
                continue;
            }

            /** @var PlanPrice|null $monthly */
            $monthly = $prices->get(1);
            $fullPrice = $monthly === null ? null : $monthly->price_paise * $duration;
            $savings = $fullPrice !== null && $fullPrice > $price->price_paise
                ? (int) floor(($fullPrice - $price->price_paise) * 100 / $fullPrice)
                : null;

            $cards[] = new PlanCardData(
                key: $plan->code->value,
                name: $plan->name,
                durationMonths: $duration,
                price: $price->price(),
                // Display only, to the nearest rupee (₹17,991 / 12 → ₹1,499 a month); the billed price is exact.
                perMonth: Money::paise(intdiv($price->price_paise + 50 * $duration, 100 * $duration) * 100),
                savingsPercent: $savings > 0 ? $savings : null,
                features: $plan->display_features,
                isFeatured: $plan->is_featured,
                badge: $plan->badge,
                ctaUrl: $toCheckout
                    ? route('member.checkout', ['plan' => strtolower($plan->code->value), 'duration' => $duration])
                    : $ctaUrl,
            );
        }

        return $cards;
    }

    /** @return list<int> every duration at least one purchasable plan is sold for, shortest first */
    public function durations(): array
    {
        return $this->plans()
            ->flatMap(fn (Plan $plan): Collection => $plan->prices->pluck('duration_months'))
            ->map(fn (mixed $months): int => (int) $months)
            ->unique()->sort()->values()->all();
    }

    /** @return list<int> the durations a purchasable plan is sold for, shortest first ([] = not on sale) */
    public function durationsFor(PlanCode $code): array
    {
        $plan = $code === PlanCode::Free ? null : $this->plans()->first(fn (Plan $plan): bool => $plan->code === $code);

        return $plan === null ? [] : $plan->prices
            ->map(fn (PlanPrice $price): int => $price->duration_months)
            ->sort()->values()->all();
    }

    /** @return list<string> the plan's marketing bullet list (A06 "display features") */
    public function displayFeatures(PlanCode $code): array
    {
        $plan = Plan::query()->where('code', $code->value)->first();

        return $plan === null ? [] : $plan->display_features;
    }

    /** @return array{0: Plan, 1: PlanPrice}|null the plan and its active price, when it can be bought */
    public function purchasable(PlanCode $code, int $duration): ?array
    {
        if ($code === PlanCode::Free) {
            return null;
        }

        $plan = Plan::query()->purchasable()->where('code', $code->value)->first();
        $price = $plan?->prices()->where('is_active', true)->where('duration_months', $duration)->first();

        return $plan !== null && $price !== null ? [$plan, $price] : null;
    }

    /** Free + every purchasable plan, one column each, one row per §7.3 entitlement. */
    public function comparison(): PlanComparisonData
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('is_purchasable', true)->orWhere('code', PlanCode::Free->value))
            ->with('features')
            ->orderBy('sort_order')
            ->get();

        $rows = [];
        foreach (Entitlement::cases() as $entitlement) {
            $cells = [];
            foreach ($plans as $plan) {
                $feature = $plan->features->first(fn (PlanFeature $row): bool => $row->entitlement === $entitlement);
                $cells[] = $this->cell($entitlement, $feature);
            }
            $rows[] = ['label' => $entitlement->label(), 'cells' => $cells];
        }

        $featured = $plans->search(fn (Plan $plan): bool => $plan->is_featured);

        return new PlanComparisonData(
            plans: $plans->map(fn (Plan $plan): string => $plan->name)->values()->all(),
            featuredColumn: $featured === false ? null : (int) $featured,
            rows: $rows,
        );
    }

    /** @return Collection<int, Plan> purchasable plans with their active prices (read once per instance) */
    private function plans(): Collection
    {
        return $this->plans ??= Plan::query()
            ->purchasable()
            ->with(['prices' => fn ($query) => $query->where('is_active', true)])
            ->get();
    }

    private function cell(Entitlement $entitlement, ?PlanFeature $feature): bool|string
    {
        if ($feature === null || ! $feature->is_enabled) {
            return false;
        }

        if ($entitlement->isFlag()) {
            return true;
        }

        return match ($feature->limit_value) {
            null => __('Unlimited'),
            0 => false,
            default => number_format($feature->limit_value),
        };
    }
}
