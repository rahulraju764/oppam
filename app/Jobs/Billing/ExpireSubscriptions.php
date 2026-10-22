<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Actions\Billing\SyncMembershipFlags;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Billing\PlanExpiredNotification;
use App\Queries\Billing\CurrentMembership;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Every 10 minutes: ACTIVE subscriptions past their end become EXPIRED (PRD M10 "on expiry revert
 * to Free limits (data kept)"). Limits already follow the clock (EntitlementService reads the
 * subscription in force); this job brings the rest in step, per member:
 *  - the denormalised flags (is_premium, highlight, top placement) — SyncMembershipFlags;
 *  - `.entitlements.changed` to the member's open pages (composer locks, quotas refresh);
 *  - `plan_expired` once — only when no plan follows (a queued renewal simply takes over).
 * Batched by member, each in its own transaction with the profile row locked; safe to re-run.
 */
final class ExpireSubscriptions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    private const BATCH = 200;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(SyncMembershipFlags $flags, CurrentMembership $membership): void
    {
        $now = CarbonImmutable::now();

        do {
            $profileIds = Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->whereNull('paused_at')   // a suspended member's plan is paused, not ending (A03)
                ->where('ends_at', '<=', $now)
                ->distinct()
                ->limit(self::BATCH)
                ->pluck('profile_id');

            foreach ($profileIds as $profileId) {
                $this->expireFor((string) $profileId, $now, $flags, $membership);
            }
        } while ($profileIds->count() === self::BATCH);
    }

    private function expireFor(string $profileId, CarbonImmutable $now, SyncMembershipFlags $flags, CurrentMembership $membership): void
    {
        DB::transaction(function () use ($profileId, $now, $flags, $membership): void {
            $profile = Profile::withTrashed()->whereKey($profileId)->lockForUpdate()->first();

            $ended = Subscription::query()
                ->where('profile_id', $profileId)
                ->where('status', SubscriptionStatus::Active->value)
                ->whereNull('paused_at')
                ->where('ends_at', '<=', $now)
                ->with('plan')
                ->latest('ends_at')
                ->get();
            if ($ended->isEmpty()) {
                return;
            }

            Subscription::query()->whereKey($ended->modelKeys())->update([
                'status' => SubscriptionStatus::Expired->value,
                'updated_at' => $now,
            ]);
            if ($profile === null) {   // can't happen (restrict FK) — but never leave rows the batch would select again
                return;
            }

            $inForce = $flags->handle($profile, $now);
            $user = $profile->user_id === null ? null : User::query()->find($profile->user_id);
            if ($user === null) {
                return;
            }

            $current = $membership->current($profile, $now);
            EntitlementsChanged::dispatch($user->id, $inForce->code->value, $inForce->name,
                $current === null ? '' : $current->ends_at->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y'));

            if (! $membership->running($profile, $now)->exists()) {
                $planName = $ended->first()?->plan->name ?? '';
                DB::afterCommit(fn () => $user->notify(new PlanExpiredNotification($planName)));
            }
        });
    }
}
