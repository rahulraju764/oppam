<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Actions\Billing\SyncMembershipFlags;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A06 "entitlement changes → immediate": every member running this plan gets their flags re-synced
 * (highlight, top placement) and EntitlementsChanged, so open pages re-read their limits. Chunked;
 * a re-run only repeats the hint. Limits themselves are read from plan_features on every check.
 */
final class ApplyPlanEntitlements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly string $planId)
    {
        $this->onQueue('default');
    }

    public function handle(SyncMembershipFlags $flags): void
    {
        $plan = Plan::query()->find($this->planId);
        if ($plan === null) {
            return;
        }

        $timezone = (string) config('oppam.display_timezone');

        Subscription::query()->where('plan_id', $plan->id)->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->with('profile.user')
            ->chunkById(500, function ($subscriptions) use ($flags, $plan, $timezone): void {
                foreach ($subscriptions as $subscription) {
                    $profile = $subscription->profile;
                    if ($profile === null) {
                        continue;
                    }

                    $flags->handle($profile);
                    if ($profile->user !== null) {
                        EntitlementsChanged::dispatch($profile->user->id, $plan->code->value, $plan->name, $subscription->ends_at->copy()->timezone($timezone)->format('j M Y'));
                    }
                }
            });
    }
}
