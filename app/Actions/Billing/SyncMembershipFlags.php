<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Profile;
use App\Queries\Billing\CurrentMembership;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;

/**
 * Keeps the profile's denormalised plan flags in step with its subscriptions (PRD §7.3, M10) — the
 * ONE place they are written:
 *  - is_premium          a paid or complimentary plan is in force (premium-only photos, badge, priority);
 *  - highlighted_until   the plan allows `search_highlight` (Gold, Diamond) → until it ends;
 *  - top_placement_until the plan allows `top_placement` (Diamond) → until it ends.
 * Search reads these columns (never a plan per row). Called after every subscription change:
 * activation, complimentary grant, expiry, pause / resume (A03 suspension). Everything is read from
 * the subscription in force at `$now`, so the flags and the plan returned always agree. Returns the
 * plan in force (Free when none).
 */
final class SyncMembershipFlags
{
    public function __construct(
        private readonly CurrentMembership $membership,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Profile $profile, ?CarbonImmutable $now = null): Plan
    {
        $this->entitlements->forget($profile);
        $current = $this->membership->current($profile, $now ?? CarbonImmutable::now());
        $plan = $current?->plan;
        $plan?->loadMissing('features');
        $until = $current?->ends_at;

        $profile->forceFill([
            'is_premium' => $current !== null,
            'highlighted_until' => $plan !== null && self::has($plan, Entitlement::SearchHighlight) ? $until : null,
            'top_placement_until' => $plan !== null && self::has($plan, Entitlement::TopPlacement) ? $until : null,
        ]);

        if ($profile->isDirty()) {
            $profile->save();
        }

        return $plan ?? Plan::query()->where('code', PlanCode::Free->value)->firstOrFail();
    }

    private static function has(Plan $plan, Entitlement $flag): bool
    {
        return $plan->features->contains(fn (PlanFeature $feature): bool => $feature->entitlement === $flag && $feature->is_enabled);
    }
}
