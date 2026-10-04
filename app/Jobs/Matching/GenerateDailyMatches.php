<?php

declare(strict_types=1);

namespace App\Jobs\Matching;

use App\Domain\Matching\MatchScorer;
use App\Domain\Safety\BlockList;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Daily matches generator (PRD §10 M05, §12 F06).
 * Runs at 05:00 IST on the `matching` queue, chunked per 1,000 members.
 * Filters candidates by hard partner preferences, scores with MatchScorer,
 * enforces district diversity (max 3 per district), and stores top 10 matches.
 */
final class GenerateDailyMatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  list<string>|null  $profileIds
     */
    public function __construct(
        public readonly ?array $profileIds = null,
        public readonly ?string $forDate = null
    ) {
        $this->onQueue('matching');
    }

    public function handle(
        MatchScorer $scorer,
        BlockList $blockList,
        SettingsRepository $settings
    ): void {
        $today = $this->forDate ?? CarbonImmutable::now('Asia/Kolkata')->toDateString();

        if ($this->profileIds === null) {
            /** @var list<string> $allIds */
            $allIds = Profile::query()
                ->where('status', ProfileStatus::Active->value)
                ->whereNotNull('published_at')
                ->pluck('id')
                ->all();

            if (count($allIds) > 1000) {
                foreach (array_chunk($allIds, 1000) as $chunk) {
                    self::dispatch($chunk, $today);
                }

                return;
            }

            $profileIds = $allIds;
        } else {
            $profileIds = $this->profileIds;
        }

        /** @var int $matchLimit */
        $matchLimit = $settings->get(SettingKey::DailyMatchCount);
        if ($matchLimit <= 0) {
            $matchLimit = 10;
        }

        foreach ($profileIds as $profileId) {
            $this->processProfile($profileId, $today, $matchLimit, $scorer, $blockList);
        }
    }

    private function processProfile(
        string $profileId,
        string $today,
        int $matchLimit,
        MatchScorer $scorer,
        BlockList $blockList
    ): void {
        $source = Profile::query()
            ->with(['partnerPreference', 'educationCareer'])
            ->find($profileId);

        if ($source === null || $source->status !== ProfileStatus::Active || $source->published_at === null) {
            return;
        }

        $oppositeGender = $source->gender === Gender::Male ? Gender::Female : Gender::Male;
        $blockedIds = $blockList->hiddenFrom($source);
        $pref = $source->partnerPreference;

        $candidateQuery = Profile::query()
            ->with(['partnerPreference', 'educationCareer', 'media'])
            ->where('status', ProfileStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('gender', $oppositeGender->value)
            ->where('id', '!=', $source->id)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('privacy_settings')
                ->whereColumn('privacy_settings.profile_id', 'profiles.id')
                ->where('privacy_settings.incognito', true))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('ignores')
                ->whereColumn('ignores.ignored_profile_id', 'profiles.id')
                ->where('ignores.ignorer_profile_id', $source->id));

        if ($blockedIds !== []) {
            $candidateQuery->whereNotIn('id', $blockedIds);
        }

        // Hard preference constraints when preferences are defined
        if ($pref !== null) {
            if ($pref->age_min !== null) {
                $maxDob = CarbonImmutable::now()->subYears($pref->age_min);
                $candidateQuery->where('dob', '<=', $maxDob);
            }

            if ($pref->age_max !== null) {
                $minDob = CarbonImmutable::now()->subYears($pref->age_max + 1)->addDay();
                $candidateQuery->where('dob', '>=', $minDob);
            }

            if (! empty($pref->religion_ids)) {
                $candidateQuery->whereIn('religion_id', $pref->religion_ids);
            }

            if (! empty($pref->marital_statuses)) {
                $candidateQuery->whereIn('marital_status', $pref->marital_statuses);
            }
        }

        // Take up to 200 candidates to score
        /** @var \Illuminate\Database\Eloquent\Collection<int, Profile> $candidates */
        $candidates = $candidateQuery->limit(200)->get();

        if ($candidates->isEmpty()) {
            return;
        }

        // Score candidates
        /** @var list<array{profile: Profile, score: int}> $scored */
        $scored = [];
        foreach ($candidates as $candidate) {
            $scoreData = $scorer->calculate($source, $candidate);
            $scored[] = [
                'profile' => $candidate,
                'score' => $scoreData->totalScore,
            ];
        }

        // Sort descending by score
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Diversity cap: max 3 from any single district (PRD §10 M05)
        /** @var array<int|string, int> $districtCounts */
        $districtCounts = [];
        /** @var list<array{profile: Profile, score: int}> $selected */
        $selected = [];

        foreach ($scored as $item) {
            /** @var Profile $cand */
            $cand = $item['profile'];
            $districtKey = $cand->district_id !== null ? (string) $cand->district_id : 'none';
            $currentDistrictCount = $districtCounts[$districtKey] ?? 0;

            if ($districtKey !== 'none' && $currentDistrictCount >= 3) {
                continue; // Skip: diversity cap reached for this district
            }

            $districtCounts[$districtKey] = $currentDistrictCount + 1;
            $selected[] = $item;

            if (count($selected) >= $matchLimit) {
                break;
            }
        }

        // Persist daily matches in a transaction
        DB::transaction(function () use ($source, $today, $selected): void {
            foreach ($selected as $match) {
                DailyMatch::query()->updateOrCreate(
                    [
                        'profile_id' => $source->id,
                        'matched_profile_id' => $match['profile']->id,
                        'match_date' => $today,
                    ],
                    [
                        'score' => $match['score'],
                    ]
                );
            }
        });
    }
}
