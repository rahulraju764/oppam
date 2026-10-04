<?php

declare(strict_types=1);

namespace App\Livewire\Member\Search;

use App\Actions\Search\FindProfileById;
use App\Actions\Search\SaveSearch;
use App\Actions\Search\SearchProfiles;
use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileCards;
use App\Enums\AlertFrequency;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\SearchSort;
use App\Exceptions\Search\SavedSearchLimitReached;
use App\Exceptions\Search\SearchThrottled;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search (M04): find by profile ID, or filter. Every filter lives in the URL (`?f[...]`), so a
 * copied link or the back button gives the same search; inputs update live (text debounced).
 * Results load 20 at a time — "Load more" or scrolling to the end. The query and every
 * exclusion (blocked, ignored, incognito, same gender…) are in ProfileSearch via SearchProfiles;
 * this component only holds the state and renders cards (codes and display data only).
 */
final class Search extends Component
{
    /** @var array<string, mixed> filter values as typed / in the URL; parsed by SearchCriteria::fromInput */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    public string $profileId = '';

    public ?string $idError = null;

    public ?string $notice = null;

    /** @var list<array<string, mixed>> the cards shown so far (display data only — codes, never ids) */
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

    public function openSaveModal(): void
    {
        $this->saveName = __('Search - :date', ['date' => now()->format('d M')]);
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
                filters: $this->filters,
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

    public function mount(): void
    {
        $this->search();
    }

    public function updatedFilters(mixed $value, string $key): void
    {
        // A new religion / country / state makes the castes / states / districts chosen under it meaningless.
        $dependents = ['religion' => ['caste'], 'country' => ['state', 'district'], 'state' => ['district']];
        foreach ($dependents[$key] ?? [] as $child) {
            unset($this->filters[$child]);
        }

        $this->filters = array_filter($this->filters, fn (mixed $v): bool => $v !== '' && $v !== null && $v !== [] && $v !== false);
        $this->search();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->search();
    }

    public function loadMore(SearchProfiles $search, ProfileCards $cards): void
    {
        if ($this->cursor === null) {
            return;
        }

        $this->run($search, $cards, append: true);
    }

    /** Find by profile ID (M04 mode 1): opens the profile, or one message whether it's missing or not visible. */
    public function findById(FindProfileById $find): mixed
    {
        $this->idError = null;

        try {
            $profile = $find->handle($this->member(), $this->profileId);
        } catch (SearchThrottled $throttled) {
            $this->idError = $throttled->getMessage();

            return null;
        }

        if ($profile === null) {
            $this->idError = __('No profile with that ID. Check the ID and try again.');

            return null;
        }

        return $this->redirectRoute('member.profile.show', ['profile' => $profile->code], navigate: true);
    }

    public function render(Masters $masters): View
    {
        $f = $this->filters;
        $religion = is_numeric($f['religion'] ?? null) ? (int) $f['religion'] : null;
        $country = is_numeric($f['country'] ?? null) ? (int) $f['country'] : null;
        $state = is_numeric($f['state'] ?? null) ? (int) $f['state'] : null;
        $own = $this->member()->profile;

        return view('livewire.member.search.search', [
            'cards' => array_map(fn (array $card): ProfileCardData => new ProfileCardData(...$card), $this->results),
            'lookingFor' => $own?->gender === Gender::Male ? __('Bride') : __('Groom'),
            'religions' => Masters::forSelect($masters->religions()),
            'castes' => $religion !== null ? Masters::forSelect($masters->castesForReligion($religion)) : [],
            'motherTongues' => Masters::forSelect($masters->motherTongues()),
            'stars' => Masters::forSelect($masters->stars()),
            'rasis' => Masters::forSelect($masters->rasis()),
            'countries' => Masters::forSelect($masters->countries()),
            'states' => $country !== null ? Masters::forSelect($masters->statesForCountry($country)) : [],
            'districts' => Masters::forSelect($state !== null ? $masters->districtsForState($state) : $masters->allDistricts()),
            'education' => Masters::forSelect($masters->education()),
            'occupations' => Masters::forSelect($masters->occupations()),
            'incomeBands' => Masters::forSelect($masters->incomeBands()),
            'options' => collect(['family_status', 'family_type', 'family_values', 'diet', 'smoking', 'drinking'])
                ->mapWithKeys(fn (string $g): array => [$g => Masters::forSelect($masters->options($g))])->all(),
            'maritalStatuses' => MaritalStatus::options(),
            'physicalStatuses' => PhysicalStatus::options(),
            'employerTypes' => EmployerType::options(),
            'sorts' => SearchSort::options(),
            'ages' => range(SearchCriteria::AGE_MIN, SearchCriteria::AGE_MAX),
            'heightMin' => HeightCm::MIN,
            'heightMax' => HeightCm::MAX,
            'activeFilters' => count(array_diff_key($this->filters, ['sort' => true])),
        ])->layout('layouts::member', ['seo' => SeoData::private('Search | Oppam Matrimony')]);
    }

    private function search(): void
    {
        $this->run(app(SearchProfiles::class), app(ProfileCards::class), append: false);
    }

    private function run(SearchProfiles $search, ProfileCards $cards, bool $append): void
    {
        $this->notice = null;

        try {
            $result = $search->handle($this->member(), SearchCriteria::fromInput($this->filters), $append ? $this->cursor : null, withCount: ! $append);
        } catch (SearchThrottled $throttled) {
            $this->notice = $throttled->getMessage();

            return;
        }

        $page = array_map(fn (ProfileCardData $card): array => get_object_vars($card), $cards->forViewers($result->page->profiles, $this->member()));
        $this->results = $append ? [...$this->results, ...$page] : $page;
        $this->cursor = $result->page->nextCursor;

        if ($result->total !== null) {
            $this->total = $result->total;
        }
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
