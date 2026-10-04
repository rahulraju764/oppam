<?php

declare(strict_types=1);

namespace App\Livewire\Member\Matches;

use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Domain\Matching\MatchScorer;
use App\Domain\Profile\ProfileCards;
use App\Domain\Safety\BlockList;
use App\Enums\UserRole;
use App\Jobs\Matching\GenerateDailyMatches;
use App\Models\DailyMatch;
use App\Models\Profile;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Daily match recommendations page (PRD §10 M05, §12 F06).
 * Shows today's 10 hand-picked matches with match scores and a countdown timer.
 */
#[Layout('layouts::member')]
final class Daily extends Component
{
    public function render(
        MatchScorer $scorer,
        BlockList $blockList,
        SettingsRepository $settings,
        ProfileCards $cardsService
    ): View {
        $user = $this->member();
        /** @var Profile $profile */
        $profile = $user->profile;

        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $expiresAt = CarbonImmutable::now('Asia/Kolkata')->endOfDay()->toIso8601String();

        /** @var Collection<int, DailyMatch> $dailyMatches */
        $dailyMatches = DailyMatch::query()
            ->where('profile_id', $profile->id)
            ->where('match_date', $today)
            ->where('is_interacted', false)
            ->with([
                'matchedProfile' => function ($q): void {
                    $q->with(['media', 'educationCareer', 'partnerPreference', 'privacySetting']);
                },
            ])
            ->orderByDesc('score')
            ->get();

        // If empty (e.g. before 05:00 IST scheduled run or first day), generate on-demand
        if ($dailyMatches->isEmpty()) {
            (new GenerateDailyMatches([$profile->id], $today))->handle($scorer, $blockList, $settings);

            /** @var Collection<int, DailyMatch> $dailyMatches */
            $dailyMatches = DailyMatch::query()
                ->where('profile_id', $profile->id)
                ->where('match_date', $today)
                ->where('is_interacted', false)
                ->with([
                    'matchedProfile' => function ($q): void {
                        $q->with(['media', 'educationCareer', 'partnerPreference', 'privacySetting']);
                    },
                ])
                ->orderByDesc('score')
                ->get();
        }

        // Mark unviewed matches as viewed
        DailyMatch::query()
            ->where('profile_id', $profile->id)
            ->where('match_date', $today)
            ->where('is_viewed', false)
            ->update(['is_viewed' => true]);

        /** @var list<array{match: DailyMatch, card: ProfileCardData}> $matchCards */
        $matchCards = [];
        foreach ($dailyMatches as $dm) {
            if ($dm->matchedProfile !== null) {
                $matchCards[] = [
                    'match' => $dm,
                    'card' => $cardsService->forViewer($dm->matchedProfile, $user),
                ];
            }
        }

        return view('livewire.member.matches.daily', [
            'matchCards' => $matchCards,
            'today' => $today,
            'expiresAt' => $expiresAt,
            'profile' => $profile,
            'matchCount' => count($matchCards),
        ])->layoutData([
            'seo' => SeoData::private(__('Daily Matches | Oppam Matrimony')),
        ]);
    }

    public function dismissMatch(string $matchId): void
    {
        $user = $this->member();
        $profile = $user->profile;

        if ($profile === null) {
            return;
        }

        DailyMatch::query()
            ->where('id', $matchId)
            ->where('profile_id', $profile->id)
            ->update(['is_interacted' => true]);
    }

    private function member(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member || ! $user->profile instanceof Profile) {
            abort(404);
        }

        return $user;
    }
}
