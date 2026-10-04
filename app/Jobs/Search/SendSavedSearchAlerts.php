<?php

declare(strict_types=1);

namespace App\Jobs\Search;

use App\Domain\Profile\ProfileVisibility;
use App\Enums\AlertFrequency;
use App\Models\NotificationPreference;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\Member\SavedSearchMatchesNotification;
use App\Services\Profile\ProfileSearch;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Saved-search alerts (M04), scheduled daily at 08:00 IST: every DAILY search not alerted in the
 * last 20 hours and every WEEKLY one not alerted in 6 days counts the profiles published since its
 * last alert (or since it was saved) with the owner's full search rules — blocked, ignored,
 * incognito and non-ACTIVE profiles never count. Owners who may not browse (suspended account or
 * profile) get nothing; an email goes only when there is something new and the owner hasn't turned
 * `saved_search_alert` emails off. The window moves on either way, so nothing is counted twice.
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

    public function handle(ProfileSearch $search, ProfileVisibility $visibility): void
    {
        $now = CarbonImmutable::now();

        SavedSearch::query()
            ->with(['profile.user.notificationPreferences'])
            ->where('alert_frequency', '!=', AlertFrequency::Off->value)
            ->when($this->frequency !== null, fn ($q) => $q->where('alert_frequency', $this->frequency?->value))
            ->lazyById(100)
            ->each(function (SavedSearch $saved) use ($search, $visibility, $now): void {
                $profile = $saved->profile;
                $owner = $profile?->user?->setRelation('profile', $profile);

                if ($profile === null || $owner === null || ! $visibility->canBrowse($owner) || ! $this->isDue($saved, $now)) {
                    return;
                }

                $count = $search->query($profile, $saved->criteria())
                    ->where('profiles.published_at', '>', $saved->last_alerted_at ?? $saved->created_at)
                    ->where('profiles.published_at', '<=', $now)
                    ->count();

                if ($count > 0 && $this->wantsEmail($owner)) {
                    $owner->notify(new SavedSearchMatchesNotification($saved, $count));
                }

                $saved->forceFill(['last_alerted_at' => $now])->save();
            });
    }

    private function isDue(SavedSearch $saved, CarbonImmutable $now): bool
    {
        if ($saved->last_alerted_at === null) {
            return true;
        }

        return match ($saved->alert_frequency) {
            AlertFrequency::Daily => $saved->last_alerted_at->lte($now->subHours(20)),
            AlertFrequency::Weekly => $saved->last_alerted_at->lte($now->subDays(6)),
            AlertFrequency::Off => false,
        };
    }

    /** On unless the member turned this event's email off (notification_preferences, M08/M14). */
    private function wantsEmail(User $owner): bool
    {
        $preference = $owner->notificationPreferences
            ->first(fn (NotificationPreference $p): bool => $p->event === SavedSearchMatchesNotification::EVENT);

        return $preference === null || $preference->email;
    }
}
