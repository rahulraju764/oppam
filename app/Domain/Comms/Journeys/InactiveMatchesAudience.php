<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\JourneyKey;
use App\Enums\ProfileStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A14 "inactive 14 d — new matches for you": an ACTIVE profile last active `offset` days ago that has
 * daily matches (P2.4) picked since then. No new matches → nothing to say, no message. The subject
 * is the inactive spell (its last-active moment), so a later spell gets its own reminder.
 * Matches are counted for 500 profiles at a time (one grouped query per chunk).
 */
final class InactiveMatchesAudience implements JourneyAudience
{
    private const CHUNK = 500;

    public function key(): JourneyKey
    {
        return JourneyKey::InactiveMatches;
    }

    public function candidates(int $offset, CarbonImmutable $now): iterable
    {
        $window = JourneyWindow::for($offset, $now);
        $tz = (string) config('oppam.display_timezone');

        $rows = ReachableMembers::query()
            ->where('profiles.status', ProfileStatus::Active->value)
            ->where('profiles.last_active_at', '>=', $window->from)
            ->where('profiles.last_active_at', '<', $window->until)
            ->select(['profiles.id as profile_id', 'profiles.last_active_at', 'users.id as user_id'])
            ->lazyById(self::CHUNK, 'profiles.id', 'profile_id')
            ->chunk(self::CHUNK);

        foreach ($rows as $chunk) {
            // Every match day since the earliest last-active day in the window, grouped per profile and day.
            $since = $window->from->setTimezone($tz)->toDateString();
            $perDay = DB::table('daily_matches')
                ->whereIn('profile_id', $chunk->pluck('profile_id')->all())
                ->where('match_date', '>', $since)
                ->selectRaw('profile_id, match_date, COUNT(*) as n')
                ->groupBy('profile_id', 'match_date')
                ->get()
                ->groupBy('profile_id');

            foreach ($chunk as $row) {
                $lastActive = CarbonImmutable::parse((string) $row->last_active_at, 'UTC');
                $lastDay = $lastActive->setTimezone($tz)->toDateString();
                $waiting = (int) $perDay->get((string) $row->profile_id, collect())
                    ->filter(fn (object $d): bool => (string) $d->match_date > $lastDay)
                    ->sum('n');

                if ($waiting > 0) {
                    yield new JourneyCandidate(userId: (string) $row->user_id, subjectRef: (string) $lastActive->timestamp, count: $waiting);
                }
            }
        }
    }
}
