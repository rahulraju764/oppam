<?php

declare(strict_types=1);

namespace App\Livewire\Member\Browse;

use App\Actions\Search\DeleteSavedSearch;
use App\Actions\Search\SearchProfiles;
use App\Actions\Search\UpdateSavedSearch;
use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileCards;
use App\Domain\Search\OwnSavedSearch;
use App\Enums\AlertFrequency;
use App\Enums\SearchSort;
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
 * All Profiles (M04, template all-profiles.php): every profile the member may see, with the four
 * sorts, 20 at a time. The left rail manages the member's saved searches (made on /search): run,
 * rename, alert frequency, delete — each through an Action that only finds the member's own.
 */
final class AllProfiles extends Component
{
    #[Url]
    public string $sort = 'relevance';

    /** @var list<array<string, mixed>> the cards shown so far (display data only — codes, never ids) */
    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $cursor = null;

    #[Locked]
    public int $total = 0;

    public ?string $notice = null;

    /** The saved search being renamed (set only by startRename, from the member's own list). */
    #[Locked]
    public ?string $renamingId = null;

    public string $renameTo = '';

    public function mount(): void
    {
        $this->load(append: false);
    }

    public function updatedSort(): void
    {
        $this->cursor = null;
        $this->load(append: false);
    }

    public function loadMore(): void
    {
        if ($this->cursor !== null) {
            $this->load(append: true);
        }
    }

    public function updateFrequency(string $savedSearchId, string $frequency, UpdateSavedSearch $update): void
    {
        $choice = AlertFrequency::tryFrom($frequency);
        if ($choice === null) {
            return;   // not one of the offered values: change nothing
        }

        $update->handle($this->member(), $savedSearchId, frequency: $choice);
        $this->notice = __('Alert setting saved.');
    }

    public function startRename(string $savedSearchId): void
    {
        $search = $this->ownSearch($savedSearchId);
        $this->resetErrorBag();
        $this->renamingId = $search->id;
        $this->renameTo = $search->name;
        $this->dispatch('open-modal', name: 'rename-search');
    }

    public function rename(UpdateSavedSearch $update): void
    {
        $this->resetErrorBag();

        if ($this->renamingId === null) {
            return;
        }

        try {
            $update->handle($this->member(), $this->renamingId, name: $this->renameTo);
        } catch (ValidationException $e) {
            $this->addError('renameTo', (string) $e->validator->errors()->first());

            return;
        }

        $this->renamingId = null;
        $this->notice = __('Saved search renamed.');
        $this->dispatch('close-modal', name: 'rename-search');
    }

    public function deleteSavedSearch(string $savedSearchId, DeleteSavedSearch $delete): void
    {
        $delete->handle($this->member(), $savedSearchId);
        $this->notice = __('Saved search deleted.');
    }

    public function render(): View
    {
        $profile = $this->member()->profile;

        return view('livewire.member.browse.all-profiles', [
            'cards' => array_map(fn (array $c): ProfileCardData => new ProfileCardData(...$c), $this->results),
            'savedSearches' => $profile?->savedSearches()->latest()->get() ?? collect(),
            'sorts' => SearchSort::options(),
            'frequencies' => AlertFrequency::options(),
            'maxSaved' => SavedSearch::MAX_PER_PROFILE,
        ])->layout('layouts::member', [
            'seo' => SeoData::private('All Profiles | Oppam Matrimony'),
        ]);
    }

    private function load(bool $append): void
    {
        $member = $this->member();
        $criteria = new SearchCriteria(sort: SearchSort::tryFrom($this->sort) ?? SearchSort::Relevance);

        try {
            $result = app(SearchProfiles::class)->handle($member, $criteria, $append ? $this->cursor : null, withCount: ! $append);
        } catch (SearchThrottled $throttled) {
            $this->notice = $throttled->getMessage();

            return;
        }

        $page = array_map(fn (ProfileCardData $card): array => get_object_vars($card), app(ProfileCards::class)->forViewers($result->page->profiles, $member));
        $this->results = $append ? [...$this->results, ...$page] : $page;
        $this->cursor = $result->page->nextCursor;

        if ($result->total !== null) {
            $this->total = $result->total;
        }

        // Prev / Next on a profile opened from these results (M03) walk the list in this order.
        app(ProfileBrowseList::class)->remember(array_column($this->results, 'code'));
    }

    private function ownSearch(string $savedSearchId): SavedSearch
    {
        return OwnSavedSearch::find($this->member(), $savedSearchId);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
