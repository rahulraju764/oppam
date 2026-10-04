<?php

declare(strict_types=1);

namespace App\Jobs\Search;

use App\Enums\AlertFrequency;
use App\Enums\ProfileStatus;
use App\Models\SavedSearch;
use App\Notifications\Member\SavedSearchMatchesNotification;
use App\Services\Profile\ProfileSearch;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scheduled job to send daily/weekly email alerts for saved searches (PRD §10 M04).
 * Finds new active profiles published strictly after last_alerted_at (or created_at if first run)
 * and notifies the member with the count, search link, and 1-click signed unsubscribe link.
 */
final class SendSavedSearchAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly ?AlertFrequency $frequency = null)
    {
        $this->onQueue('notifications');
    }

    public function handle(ProfileSearch $search): void
    {
        $now = CarbonImmutable::now();

        $query = SavedSearch::query()
            ->with(['profile.user'])
            ->where('alert_frequency', '!=', AlertFrequency::Off->value);

        if ($this->frequency !== null) {
            $query->where('alert_frequency', $this->frequency->value);
        }

        $query->lazyById(100)->each(function (SavedSearch $savedSearch) use ($search, $now): void {
            $profile = $savedSearch->profile;

            if ($profile === null || $profile->status !== ProfileStatus::Active || $profile->user === null) {
                return;
            }

            if (! $this->isDue($savedSearch, $now)) {
                return;
            }

            $since = $savedSearch->last_alerted_at ?? $savedSearch->created_at;

            $count = $search->query($profile, $savedSearch->criteria())
                ->where('profiles.published_at', '>', $since)
                ->count();

            if ($count > 0 && filled($profile->user->email)) {
                $profile->user->notify(new SavedSearchMatchesNotification($savedSearch, $count));
            }

            $savedSearch->update(['last_alerted_at' => $now]);
        });
    }

    private function isDue(SavedSearch $savedSearch, CarbonImmutable $now): bool
    {
        if ($savedSearch->last_alerted_at === null) {
            return true;
        }

        return match ($savedSearch->alert_frequency) {
            AlertFrequency::Daily => $savedSearch->last_alerted_at->lte($now->subHours(20)),
            AlertFrequency::Weekly => $savedSearch->last_alerted_at->lte($now->subDays(6)),
            AlertFrequency::Off => false,
        };
    }
}
