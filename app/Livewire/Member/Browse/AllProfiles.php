<?php

declare(strict_types=1);

namespace App\Livewire\Member\Browse;

use App\Actions\Search\DeleteSavedSearch;
use App\Actions\Search\SaveSearch;
use App\Actions\Search\SearchProfiles;
use App\Actions\Search\UpdateSavedSearch;
use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileCards;
use App\Enums\AlertFrequency;
use App\Enums\SearchSort;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Exceptions\Search\SearchThrottled;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Navigation\ProfileBrowseList;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All Profiles directory (M04, template all-profiles.php).
 * Canonical 3/6/3 layout: browse nav (col-3) | results (col-6) | ads rail (col-3).
 * Supports sorting, cursor load-more, and Saved Searches CRUD (up to 10).
 */
final class AllProfiles extends Component
{
    #[Url]
    public string $sort = 'relevance';

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $cursor = null;

    #[Locked]
    public int $total = 0;

    public bool $showSaveModal = false;

    public string $saveName = '';

    public string $saveFrequency = 'DAILY';

    public ?string $saveError = null;

    public ?string $saveSuccess = null;

    public bool $showSavedList = false;

    public function mount(SearchProfiles $searcher, ProfileCards $cards): void
    {
        $this->loadProfiles($searcher, $cards, append: false);
    }

    public function updatedSort(SearchProfiles $searcher, ProfileCards $cards): void
    {
        $this->cursor = null;
        $this->loadProfiles($searcher, $cards, append: false);
    }

    public function loadMore(SearchProfiles $searcher, ProfileCards $cards): void
    {
        if ($this->cursor !== null) {
            $this->loadProfiles($searcher, $cards, append: true);
        }
    }

    public function openSaveModal(): void
    {
        $this->saveName = __('Matches - :date', ['date' => now()->format('d M')]);
        $this->saveFrequency = 'DAILY';
        $this->saveError = null;
        $this->saveSuccess = null;
        $this->showSaveModal = true;
    }

    public function closeSaveModal(): void
    {
        $this->showSaveModal = false;
        $this->saveError = null;
    }

    public function saveSearch(SaveSearch $action): void
    {
        $profile = $this->member()->profile;

        if ($profile === null) {
            return;
        }

        $frequency = AlertFrequency::tryFrom($this->saveFrequency) ?? AlertFrequency::Daily;

        try {
            $action->handle(
                profile: $profile,
                name: $this->saveName,
                filters: ['sort' => $this->sort],
                frequency: $frequency,
            );

            $this->saveSuccess = __('Search saved successfully.');
            $this->showSaveModal = false;
        } catch (SavedSearchLimitReached $e) {
            $this->saveError = $e->getMessage();
        } catch (ValidationException $e) {
            $this->saveError = $e->validator->errors()->first();
        }
    }

    public function deleteSavedSearch(string $id, DeleteSavedSearch $action): void
    {
        $profile = $this->member()->profile;

        if ($profile === null) {
            return;
        }

        $saved = SavedSearch::query()->where('id', $id)->first();

        if ($saved !== null) {
            $action->handle($profile, $saved);
        }
    }

    public function updateFrequency(string $id, string $freq, UpdateSavedSearch $action): void
    {
        $profile = $this->member()->profile;

        if ($profile === null) {
            return;
        }

        $saved = SavedSearch::query()->where('id', $id)->first();
        $frequency = AlertFrequency::tryFrom($freq);

        if ($saved !== null && $frequency !== null) {
            $action->handle($profile, $saved, frequency: $frequency);
        }
    }

    public function toggleSavedList(): void
    {
        $this->showSavedList = ! $this->showSavedList;
    }

    public function render(): View
    {
        $member = $this->member();
        $savedSearches = $member->profile ? $member->profile->savedSearches()->latest()->get() : collect();

        return view('livewire.member.browse.all-profiles', [
            'cards' => array_map(fn (array $c): ProfileCardData => new ProfileCardData(...$c), $this->results),
            'savedSearches' => $savedSearches,
            'sorts' => SearchSort::options(),
            'frequencies' => AlertFrequency::options(),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('All Profiles | Oppam Matrimony'),
        ]);
    }

    private function loadProfiles(SearchProfiles $searcher, ProfileCards $cards, bool $append): void
    {
        $member = $this->member();
        $sortEnum = SearchSort::tryFrom($this->sort) ?? SearchSort::Relevance;
        $criteria = new SearchCriteria(sort: $sortEnum);

        try {
            $result = $searcher->handle($member, $criteria, $this->cursor, withCount: ! $append);
        } catch (SearchThrottled) {
            return;
        }

        $page = array_map(
            fn (ProfileCardData $card): array => get_object_vars($card),
            $cards->forViewers($result->page->profiles, $member),
        );

        $this->results = $append ? [...$this->results, ...$page] : $page;
        $this->cursor = $result->page->nextCursor;

        if ($result->total !== null) {
            $this->total = $result->total;
        }

        // Prev / Next on a profile opened from these results (M03) walk the list in this order.
        app(ProfileBrowseList::class)->remember(array_column($this->results, 'code'));
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
