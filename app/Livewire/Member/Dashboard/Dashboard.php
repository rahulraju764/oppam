<?php

declare(strict_types=1);

namespace App\Livewire\Member\Dashboard;

use App\Data\Content\SeoData;
use App\Data\Search\SearchCriteria;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ProfileCards;
use App\Enums\PlanCode;
use App\Enums\SearchSort;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Profile\ProfileSearch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Member Dashboard (M05, template dashboard.php).
 * Canonical 3/6/3 split:
 * - Left rail: profile card, upgrade CTA, member navigation
 * - Centre: daily recommendations (Swiper + countdown), new matches, mutual matches, premium members
 * - Right rail: shared ads rail + promos
 */
final class Dashboard extends Component
{
    public function render(
        ProfileSearch $search,
        ProfileCards $cards,
        PhotoUrls $photos,
        EntitlementService $entitlements,
    ): View {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);
        $profile->loadMissing(['partnerPreference', 'privacySetting']);

        $tz = (string) config('oppam.display_timezone');
        $now = CarbonImmutable::now($tz);
        $secondsUntilMidnight = $now->endOfDay()->diffInSeconds($now);

        $plan = $entitlements->plan($profile);
        $isGoldOrDiamond = in_array($plan->code, [PlanCode::Gold, PlanCode::Diamond], true);

        // Daily Recommendations (Relevance sort, top 8)
        $dailyResults = $search->page($profile, new SearchCriteria(sort: SearchSort::Relevance));
        $dailyCards = $cards->forViewers($dailyResults->profiles->take(8), $user);

        // New Matches (Newest sort, top 6)
        $newResults = $search->page($profile, new SearchCriteria(newlyJoined: true, sort: SearchSort::Newest));
        $newCards = $cards->forViewers($newResults->profiles->take(6), $user);

        // Premium Members (top 6)
        $premiumResults = $search->page($profile, new SearchCriteria(premiumOnly: true, sort: SearchSort::Relevance));
        $premiumCards = $cards->forViewers($premiumResults->profiles->take(6), $user);

        // Visitor counts
        $visitorsCount = ProfileView::query()->where('viewed_profile_id', $profile->id)->count();

        // Own photo URL
        $ownPhotoUrl = $photos->primaryCardUrl($profile, $user) ?? PhotoUrls::PLACEHOLDER;

        return view('livewire.member.dashboard.dashboard', [
            'profile' => $profile,
            'plan' => $plan,
            'ownPhotoUrl' => $ownPhotoUrl,
            'dailyCards' => $dailyCards,
            'newCards' => $newCards,
            'premiumCards' => $premiumCards,
            'visitorsCount' => $visitorsCount,
            'isGoldOrDiamond' => $isGoldOrDiamond,
            'secondsUntilMidnight' => (int) $secondsUntilMidnight,
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Dashboard | Oppam Matrimony'),
        ]);
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
