<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Data\Billing\MembershipSummaryData;
use App\Data\Billing\UsageMeterData;
use App\Enums\Entitlement;
use App\Models\Profile;
use App\Models\Subscription;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;

/**
 * /billing's plan card and usage meters (M10): the plan in force (Free when none), plans already
 * bought that start later, and per §7.3 quota how much is used in the current period and when it
 * resets — all read through EntitlementService, the same numbers the Actions enforce.
 */
final class MembershipSummary
{
    /** The quotas a member counts down (§7.3); free replies are per conversation, shown in chat. */
    private const METERS = [
        Entitlement::LikesPerDay,
        Entitlement::InterestsPerMonth,
        Entitlement::ContactViewsPerMonth,
        Entitlement::FavoritesTotal,
    ];

    private const FLAGS = [
        Entitlement::ChatSend,
        Entitlement::SeeWhoLikedMe,
        Entitlement::SeeWhoViewedMe,
        Entitlement::SearchHighlight,
        Entitlement::TopPlacement,
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly CurrentMembership $membership,
    ) {}

    public function for(Profile $profile): MembershipSummaryData
    {
        $now = CarbonImmutable::now();
        $current = $this->membership->current($profile, $now);
        $plan = $this->entitlements->plan($profile);

        $queued = $this->membership->running($profile, $now)
            ->where('starts_at', '>', $now)
            ->with('plan:id,name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Subscription $subscription): array => [
                'plan' => $subscription->plan->name,
                'starts_at' => CarbonImmutable::instance($subscription->starts_at),
                'ends_at' => CarbonImmutable::instance($subscription->ends_at),
            ])->values()->all();

        $meters = [];
        foreach (self::METERS as $entitlement) {
            $period = $this->entitlements->period($profile, $entitlement);
            $meters[] = new UsageMeterData(
                label: $entitlement->label(),
                used: $this->entitlements->used($profile, $entitlement),
                limit: $this->entitlements->limit($profile, $entitlement),
                resetsAt: $period->end === null ? null : CarbonImmutable::instance($period->end),
            );
        }

        $features = [];
        foreach (self::FLAGS as $entitlement) {
            $features[] = ['label' => $entitlement->label(), 'included' => $this->entitlements->allows($profile, $entitlement)];
        }

        return new MembershipSummaryData(
            plan: $plan->code,
            planName: $plan->name,
            source: $current?->source,
            endsAt: $current === null ? null : CarbonImmutable::instance($current->ends_at),
            queued: $queued,
            meters: $meters,
            features: $features,
        );
    }
}
