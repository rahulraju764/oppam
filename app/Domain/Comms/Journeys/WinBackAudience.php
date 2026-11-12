<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\JourneyKey;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * A14 "win-back (expired 30 d)": an ACTIVE profile whose LAST paid plan ENDED (status EXPIRED)
 * `offset` days ago and that has no plan running since — not renewed, upgraded or granted one. A
 * subscription that ends later than this one (a renewal chain) means this was not the last. A
 * member whose last plan was refunded (CANCELLED) is not won back: they ended it themselves.
 * The subject is the ended subscription.
 */
final class WinBackAudience implements JourneyAudience
{
    public function key(): JourneyKey
    {
        return JourneyKey::WinBack;
    }

    public function candidates(int $offset, CarbonImmutable $now): iterable
    {
        $window = JourneyWindow::for($offset, $now);

        $rows = ReachableMembers::query()
            ->join('subscriptions', 'subscriptions.profile_id', '=', 'profiles.id')
            ->where('profiles.status', ProfileStatus::Active->value)
            ->where('subscriptions.status', SubscriptionStatus::Expired->value)
            ->where('subscriptions.ends_at', '>=', $window->from)
            ->where('subscriptions.ends_at', '<', $window->until)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions as later')
                ->whereColumn('later.profile_id', 'profiles.id')
                ->where('later.status', SubscriptionStatus::Active->value)
                ->where('later.ends_at', '>', $now->utc()))
            // The ended plan must be the member's LAST one: after a renewal / upgrade chain the count
            // starts when the later plan ends, not the first (any status: a cancelled upgrade counts too).
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions as newer')
                ->whereColumn('newer.profile_id', 'profiles.id')
                ->where(fn ($w) => $w->whereColumn('newer.ends_at', '>', 'subscriptions.ends_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer.ends_at', '=', 'subscriptions.ends_at')
                        ->whereColumn('newer.id', '>', 'subscriptions.id'))))   // two plans ending at the same instant: one subject
            ->select(['subscriptions.id as subscription_id', 'users.id as user_id'])
            ->lazyById(500, 'subscriptions.id', 'subscription_id');

        foreach ($rows as $row) {
            yield new JourneyCandidate(userId: (string) $row->user_id, subjectRef: (string) $row->subscription_id);
        }
    }
}
