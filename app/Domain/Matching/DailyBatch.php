<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Data\Search\SearchCriteria;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Services\Profile\ProfileSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Today's daily matches (M05 / F06): the batch GenerateDailyMatches wrote for the member's IST
 * date, best score first, without the ones already dismissed. Read through ProfileSearch, so a
 * profile blocked, ignored, gone incognito or no longer ACTIVE since 05:00 drops out at once.
 * The batch expires at 23:59:59 IST.
 */
final class DailyBatch
{
    public function __construct(private readonly ProfileSearch $search) {}

    public static function today(): string
    {
        return CarbonImmutable::now((string) config('oppam.display_timezone'))->toDateString();
    }

    public static function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('oppam.display_timezone'))->endOfDay();
    }

    /** @return Collection<int, Profile> */
    public function profiles(Profile $me, ?int $limit = null): Collection
    {
        /** @var Collection<string, int> $scores matched profile id => score, best first */
        $scores = DailyMatch::query()
            ->where('profile_id', $me->id)
            ->where('match_date', self::today())
            ->where('is_interacted', false)
            ->orderByDesc('score')
            ->when($limit !== null, fn ($q) => $q->limit((int) $limit))
            ->pluck('score', 'matched_profile_id');

        if ($scores->isEmpty()) {
            return collect();
        }

        $order = $scores->keys()->flip();

        return $this->search->query($me, new SearchCriteria)
            ->whereIn('profiles.id', $scores->keys()->all())
            ->with('privacySetting')
            ->get()
            ->sortBy(fn (Profile $p): int => (int) $order[$p->id])
            ->values();
    }
}
