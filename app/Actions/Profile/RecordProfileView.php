<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Domain\Profile\ProfileVisibility;
use App\Events\Profile\ProfileViewed;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Count a profile view (M03, M15): one profile_views row per viewer / viewed / IST day, its
 * count bumped on repeat visits. Nothing is recorded for the owner, for a viewer who may not
 * see the profile, or for an incognito viewer (privacy_settings.incognito). The viewed member
 * gets a live ProfileViewed with today's distinct-viewer count, once per viewer per day.
 */
final class RecordProfileView
{
    public function __construct(private readonly ProfileVisibility $visibility) {}

    public function handle(User $viewer, Profile $target): void
    {
        $own = $viewer->profile;

        if ($own === null || $this->visibility->isOwner($target, $viewer) || ! $this->visibility->canView($target, $viewer)) {
            return;
        }

        if ($own->privacySetting?->incognito === true) {
            return;
        }

        $today = now((string) config('oppam.display_timezone'))->toDateString();

        $firstToday = DB::transaction(function () use ($own, $target, $today): bool {
            $inserted = ProfileView::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'viewer_profile_id' => $own->id,
                'viewed_profile_id' => $target->id,
                'view_date' => $today,
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                ProfileView::query()
                    ->where('viewer_profile_id', $own->id)
                    ->where('viewed_profile_id', $target->id)
                    ->where('view_date', $today)
                    ->update(['count' => DB::raw('LEAST(`count` + 1, 65535)'), 'updated_at' => now()]);
            }

            return $inserted === 1;
        });

        // Today's count only changes on a viewer's first visit of the day: no event per refresh.
        if ($firstToday && $target->user_id !== null) {
            $countToday = ProfileView::query()->where('viewed_profile_id', $target->id)->where('view_date', $today)->count();
            ProfileViewed::dispatch($target->user_id, $countToday);
        }
    }
}
