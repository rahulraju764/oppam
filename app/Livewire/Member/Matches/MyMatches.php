<?php

declare(strict_types=1);

namespace App\Livewire\Member\Matches;

use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileCards;
use App\Enums\SearchSort;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Profile\ProfileSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * My Matches personal funnel (M05, template my-matches.php).
 * Canonical 3/6/3 split:
 * - Left rail: funnel categories + live counts
 * - Centre: 2x2 funnel counter bar + recommended profiles (<x-profile.row>)
 * - Right rail: shared ads rail + promos
 */
final class MyMatches extends Component
{
    #[Url]
    public string $tab = 'all';

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $cursor = null;

    #[Locked]
    public int $total = 0;

    public function mount(ProfileSearch $search, ProfileCards $cards): void
    {
        $this->loadProfiles($search, $cards, append: false);
    }

    public function updatedTab(ProfileSearch $search, ProfileCards $cards): void
    {
        $this->cursor = null;
        $this->loadProfiles($search, $cards, append: false);
    }

    public function loadMore(ProfileSearch $search, ProfileCards $cards): void
    {
        if ($this->cursor !== null) {
            $this->loadProfiles($search, $cards, append: true);
        }
    }

    public function render(ProfileSearch $search): View
    {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);

        $counts = $this->calculateFunnelCounts($profile, $search);

        $funnelTabs = [
            'all' => ['label' => __('All Matches'), 'count' => $counts['all']],
            'new' => ['label' => __('Newly Joined'), 'count' => $counts['new']],
            'unviewed' => ['label' => __('Yet to be Viewed'), 'count' => $counts['unviewed']],
            'viewed' => ['label' => __('Viewed'), 'count' => $counts['viewed']],
            'near_me' => ['label' => __('Near Me'), 'count' => $counts['near_me']],
            'premium' => ['label' => __('Premium'), 'count' => $counts['premium']],
        ];

        return view('livewire.member.matches.my-matches', [
            'cards' => array_map(fn (array $c): ProfileCardData => new ProfileCardData(...$c), $this->results),
            'funnelTabs' => $funnelTabs,
            'counts' => $counts,
        ])->layout('layouts::member', [
            'seo' => SeoData::private('My Matches | Oppam Matrimony'),
        ]);
    }

    private function loadProfiles(ProfileSearch $search, ProfileCards $cards, bool $append): void
    {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);

        $criteria = match ($this->tab) {
            'new' => new SearchCriteria(newlyJoined: true, sort: SearchSort::Newest),
            'unviewed' => new SearchCriteria(hideViewed: true, sort: SearchSort::Relevance),
            'near_me' => new SearchCriteria(districtIds: $profile->district_id !== null ? [$profile->district_id] : [], sort: SearchSort::Relevance),
            'premium' => new SearchCriteria(premiumOnly: true, sort: SearchSort::Relevance),
            default => new SearchCriteria(sort: SearchSort::Relevance),
        };

        if ($this->tab === 'viewed') {
            // Viewed profiles specifically
            $viewedIds = ProfileView::query()
                ->where('viewer_profile_id', $profile->id)
                ->pluck('viewed_profile_id')
                ->all();

            $query = $search->query($profile, new SearchCriteria(sort: SearchSort::Relevance))
                ->whereIn('profiles.id', $viewedIds);

            $this->total = $query->count();
            $profiles = $query->limit(20)->get();
            $pageCards = array_map(fn (ProfileCardData $c): array => get_object_vars($c), $cards->forViewers($profiles, $user));

            $this->results = $append ? [...$this->results, ...$pageCards] : $pageCards;
            $this->cursor = null;

            return;
        }

        $page = $search->page($profile, $criteria, $this->cursor);
        $pageCards = array_map(fn (ProfileCardData $c): array => get_object_vars($c), $cards->forViewers($page->profiles, $user));

        $this->results = $append ? [...$this->results, ...$pageCards] : $pageCards;
        $this->cursor = $page->nextCursor;

        if (! $append) {
            $this->total = $search->count($profile, $criteria);
        }
    }

    /** @return array<string, int> */
    private function calculateFunnelCounts(Profile $profile, ProfileSearch $search): array
    {
        $all = $search->count($profile, new SearchCriteria(sort: SearchSort::Relevance));
        $new = $search->count($profile, new SearchCriteria(newlyJoined: true));
        $unviewed = $search->count($profile, new SearchCriteria(hideViewed: true));

        $viewed = ProfileView::query()->where('viewer_profile_id', $profile->id)->count();

        $nearMe = $profile->district_id !== null
            ? $search->count($profile, new SearchCriteria(districtIds: [$profile->district_id]))
            : 0;

        $premium = $search->count($profile, new SearchCriteria(premiumOnly: true));

        return [
            'all' => $all,
            'new' => $new,
            'unviewed' => $unviewed,
            'viewed' => $viewed,
            'near_me' => $nearMe,
            'premium' => $premium,
        ];
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
