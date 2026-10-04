<?php

declare(strict_types=1);

namespace App\Livewire\Member\Matches;

use App\Actions\Search\SearchProfiles;
use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Domain\Matching\MatchFunnel;
use App\Domain\Profile\ProfileCards;
use App\Enums\MatchTab;
use App\Exceptions\Search\SearchThrottled;
use App\Models\Profile;
use App\Models\User;
use App\Support\Navigation\ProfileBrowseList;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * My Matches (M05, template my-matches.php): the member's match funnel — 2 × 2 counters (All,
 * Yet to be viewed, Viewed, Mutual) and every tab in the left rail (a bottom sheet on phones).
 * The tabs' rules and cached counts live in MatchFunnel; results are a ProfileSearch through
 * SearchProfiles (rate limit, may-browse check), 20 at a time.
 */
final class MyMatches extends Component
{
    #[Url]
    public string $tab = 'all';

    /** @var list<array<string, mixed>> the cards shown so far (display data only — codes, never ids) */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $cursor = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->tab = $this->current()->value;
        $this->load(append: false);
    }

    public function show(string $tab): void
    {
        $this->tab = (MatchTab::tryFrom($tab) ?? MatchTab::All)->value;
        $this->cursor = null;
        $this->load(append: false);
    }

    public function updatedTab(): void
    {
        $this->show($this->tab);
    }

    public function loadMore(): void
    {
        if ($this->cursor !== null) {
            $this->load(append: true);
        }
    }

    public function render(MatchFunnel $funnel): View
    {
        $counts = $funnel->counts($this->profile());

        return view('livewire.member.matches.my-matches', [
            'cards' => array_map(fn (array $c): ProfileCardData => new ProfileCardData(...$c), $this->results),
            'current' => $this->current(),
            'counts' => $counts,
            'total' => $counts[$this->current()->value] ?? 0,
            'funnel' => MatchTab::funnel(),
            'tabs' => MatchTab::cases(),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('My Matches | Oppam Matrimony'),
        ]);
    }

    private function load(bool $append): void
    {
        $this->notice = null;
        $member = $this->member();
        $criteria = app(MatchFunnel::class)->criteria($this->profile(), $this->current());

        if ($criteria === null) {
            $this->results = [];
            $this->cursor = null;
            $this->notice = __('Add your district to your profile to see members near you.');

            return;
        }

        try {
            $result = app(SearchProfiles::class)->handle($member, $criteria, $append ? $this->cursor : null, withCount: false);
        } catch (SearchThrottled $throttled) {
            $this->notice = $throttled->getMessage();
            if (! $append) {
                // Never show the previous tab's cards under the new tab's heading.
                $this->results = [];
                $this->cursor = null;
            }

            return;
        }

        $page = array_map(fn (ProfileCardData $card): array => get_object_vars($card), app(ProfileCards::class)->forViewers($result->page->profiles, $member));
        $this->results = $append ? [...$this->results, ...$page] : $page;
        $this->cursor = $result->page->nextCursor;

        // Prev / Next on a profile opened from these results (M03) walk the list in this order.
        app(ProfileBrowseList::class)->remember(array_column($this->results, 'code'));
    }

    private function current(): MatchTab
    {
        return MatchTab::tryFrom($this->tab) ?? MatchTab::All;
    }

    private function profile(): Profile
    {
        return $this->member()->profile ?? abort(404);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
