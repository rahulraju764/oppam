<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\PlanExpiringNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily at 10:00 IST (PRD M10 "reminders at 7 days and 1 day"; decisions 2026-10-21): a plan ending
 * within the next 7 IST calendar days gets the 7-day reminder, one ending today or tomorrow the
 * 1-day reminder — each once (reminded_7d_at / reminded_1d_at), and only for the member's LAST
 * running period: a renewal that is already queued means no reminder (the slot is still marked,
 * so it isn't looked at again). A paused (suspended) subscription is skipped.
 */
final class SendPlanExpiryReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone((string) config('oppam.display_timezone'))->startOfDay();

        // 1-day first: a plan already inside the last day gets that reminder, not a late "7 days".
        $this->remind('reminded_1d_at', $now, $today->addDays(2)->utc());
        $this->remind('reminded_7d_at', $now, $today->addDays(8)->utc(), alsoSkip: 'reminded_1d_at');
    }

    private function remind(string $column, CarbonImmutable $now, CarbonImmutable $before, ?string $alsoSkip = null): void
    {
        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->whereNull($column)
            ->when($alsoSkip !== null, fn ($query) => $query->whereNull($alsoSkip))
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<', $before)
            ->with(['plan', 'profile.user'])
            ->chunkById(200, function ($subscriptions) use ($column, $now): void {
                foreach ($subscriptions as $subscription) {
                    /** @var Subscription $subscription */
                    $claimed = Subscription::query()->whereKey($subscription->id)->whereNull($column)->update([$column => $now]);
                    if ($claimed === 0 || $this->renewed($subscription, $now)) {
                        continue;
                    }

                    $subscription->profile->user?->notify(new PlanExpiringNotification($subscription->plan->name, $subscription->ends_at));
                }
            });
    }

    /** Another running period ends later (a renewal or a queued plan): nothing is about to end. */
    private function renewed(Subscription $subscription, CarbonImmutable $now): bool
    {
        return Subscription::query()
            ->where('profile_id', $subscription->profile_id)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereKeyNot($subscription->id)
            ->where('ends_at', '>', $subscription->ends_at)
            ->where('ends_at', '>', $now)
            ->exists();
    }
}
