<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\PlanChange;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The member's paid or complimentary plan as checkout sees it (M10, decisions 2026-10-21): the plan
 * in force now, and when a new purchase starts and ends — now for a first plan or an upgrade, after
 * the last running period of the same plan for an extension, after everything already bought for a
 * lower plan (only reachable when a payment for one lands while a higher plan runs).
 */
final class CurrentMembership
{
    /** The subscription in force now (latest-ending first), with its plan. */
    public function current(Profile $profile, ?CarbonImmutable $now = null): ?Subscription
    {
        return Subscription::query()
            ->where('profile_id', $profile->id)
            ->currentAt(Carbon::instance($now ?? CarbonImmutable::now()))
            ->with('plan')
            ->latest('ends_at')
            ->first();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function period(Profile $profile, string $planId, PlanChange $change, int $months, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $start = match ($change) {
            PlanChange::New, PlanChange::Upgrade => $now,
            PlanChange::Extend => $this->runningUntil($profile, $now, $planId) ?? $now,
            PlanChange::Downgrade => $this->runningUntil($profile, $now) ?? $now,
        };

        return [$start, $start->addMonthsNoOverflow($months)];
    }

    /**
     * Every ACTIVE subscription that hasn't ended yet (running or queued after the current one).
     *
     * @return Builder<Subscription>
     */
    public function running(Profile $profile, CarbonImmutable $now): Builder
    {
        return Subscription::query()
            ->where('profile_id', $profile->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('ends_at', '>', $now);
    }

    private function runningUntil(Profile $profile, CarbonImmutable $now, ?string $planId = null): ?CarbonImmutable
    {
        $until = $this->running($profile, $now)
            ->when($planId !== null, fn (Builder $query) => $query->where('plan_id', $planId))
            ->max('ends_at');

        return $until === null ? null : CarbonImmutable::parse((string) $until, 'UTC');
    }
}
