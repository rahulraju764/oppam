<?php

declare(strict_types=1);

namespace App\Actions\Matching;

use App\Domain\Matching\DailyBatch;
use App\Domain\Matching\MatchFunnel;
use App\Domain\Matching\MatchScorer;
use App\Enums\MatchTab;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Models\DailyMatch;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Notifications\Member\DailyMatchesReady;
use App\Services\Profile\ProfileSearch;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One member's daily matches for one IST date (M05 / F06):
 *  1. candidates = the member's "All matches" (their partner preferences, through ProfileSearch —
 *     blocked, ignored, incognito and non-ACTIVE never qualify), minus profiles the member already
 *     viewed and anyone in their batches of the last REPEAT_DAYS days;
 *  2. the best POOL of those by relevance (in SQL), scored with MatchScorer (60 / 25 / 10 / 5);
 *  3. diversity: at most MAX_PER_DISTRICT from one district;
 *  4. the top `matching.daily_match_count` are stored, then a "matches ready" email (respecting
 *     the member's `daily_matches_ready` email preference; in-app / live copies come in P3.2).
 * Idempotent: a member who already has a batch for that date is skipped.
 */
final class BuildDailyMatches
{
    public const POOL = 200;

    public const MAX_PER_DISTRICT = 3;

    public const REPEAT_DAYS = 30;

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly MatchFunnel $funnel,
        private readonly ProfileSearch $search,
        private readonly MatchScorer $scorer,
    ) {}

    /** @return int how many matches were stored (0 when skipped) */
    public function handle(Profile $me, ?string $date = null): int
    {
        $date ??= DailyBatch::today();

        // One build per member and date at a time: a job re-reserved by a second worker, or the
        // approval hook racing the 05:00 run, waits here instead of building (and emailing) twice.
        $lock = Cache::lock('daily-matches:'.$me->id.':'.$date, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->build($me, $date);
        } finally {
            $lock->release();
        }
    }

    private function build(Profile $me, string $date): int
    {
        $me->loadMissing(['user', 'partnerPreference', 'district']);

        if ($me->status !== ProfileStatus::Active || $me->published_at === null || $me->user === null || ! $me->user->isActive()
            || DailyMatch::query()->where('profile_id', $me->id)->where('match_date', $date)->exists()) {
            return 0;
        }

        $criteria = $this->funnel->criteria($me, MatchTab::All)?->with(['hideViewed' => true]);
        if ($criteria === null) {
            return 0;
        }

        $recent = DailyMatch::query()->where('profile_id', $me->id)
            ->where('match_date', '>=', CarbonImmutable::parse($date)->subDays(self::REPEAT_DAYS)->toDateString())
            ->pluck('matched_profile_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();

        $poolIds = $this->search->topIds($me, $criteria, self::POOL, $recent);
        if ($poolIds === []) {
            return 0;
        }

        $scored = Profile::query()->whereIn('id', $poolIds)->with(['partnerPreference', 'district', 'educationCareer'])->get()
            ->map(fn (Profile $candidate): array => ['profile' => $candidate, 'score' => $this->scorer->calculate($me, $candidate)->totalScore])
            ->sortByDesc('score')
            ->values();

        $picked = $this->diverse($scored->all(), max(1, Settings::int(SettingKey::DailyMatchCount)));

        $inserted = DB::transaction(fn (): int => DailyMatch::query()->insertOrIgnore(array_map(fn (array $m): array => [
            'id' => (string) Str::ulid(),
            'profile_id' => $me->id,
            'matched_profile_id' => $m['profile']->id,
            'match_date' => $date,
            'score' => $m['score'],
            'is_viewed' => false,
            'is_interacted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $picked)));

        // Only rows this run really added are announced — a partial earlier run sends no second email.
        if ($inserted > 0 && $this->wantsEmail($me)) {
            $me->user->notify(new DailyMatchesReady($inserted));
        }

        return $inserted;
    }

    /**
     * Best first, at most MAX_PER_DISTRICT per district (profiles without one aren't capped).
     *
     * @param  list<array{profile: Profile, score: int}>  $scored
     * @return list<array{profile: Profile, score: int}>
     */
    private function diverse(array $scored, int $limit): array
    {
        $perDistrict = [];
        $picked = [];

        foreach ($scored as $item) {
            $district = $item['profile']->district_id;
            if ($district !== null && ($perDistrict[$district] ?? 0) >= self::MAX_PER_DISTRICT) {
                continue;
            }
            if ($district !== null) {
                $perDistrict[$district] = ($perDistrict[$district] ?? 0) + 1;
            }
            $picked[] = $item;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    private function wantsEmail(Profile $me): bool
    {
        $email = NotificationPreference::query()->where('user_id', $me->user_id)
            ->where('event', DailyMatchesReady::EVENT)->value('email');

        return $email === null || (bool) $email;
    }
}
