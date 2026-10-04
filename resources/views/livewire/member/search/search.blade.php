{{--
    /search — member search (M04, template search.php: 3/6/3 dashboard row, ID search + filter card
    on the left, results in the middle, ad rail on the right). On phones the filter card becomes a
    bottom sheet opened from the "Filters" button, with "Show N profiles" to close it — built inside
    this component (the template's mobile-rail drawer moves DOM out of the Livewire root).
    Filters are wire:model'd into $filters, which lives in the URL (?f[...]).
--}}
<div x-data="{ sheet: false }" x-on:keydown.escape.window="sheet = false"
     x-effect="document.body.classList.toggle('search-sheet-open', sheet)"
     x-on:livewire:navigating.window="document.body.classList.remove('search-sheet-open')">
    <section class="dashboard-section search-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Filters (left rail / bottom sheet on phones) --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content filter-card search-sheet" x-bind:class="{ 'is-open': sheet }"
                         id="search-filters" x-trap.noscroll="sheet" role="region" aria-label="{{ __('Search filters') }}"
                         x-bind:role="sheet ? 'dialog' : 'region'" x-bind:aria-modal="sheet ? 'true' : null">
                        <section class="home-content">
                            <div class="content-header search-sheet__head">
                                <div class="content-title"><h2>{{ __('Find your match') }}</h2></div>
                                <button type="button" class="search-sheet__close d-lg-none" x-on:click="sheet = false" aria-label="{{ __('Close filters') }}">
                                    <i class="fa fa-times" aria-hidden="true"></i>
                                </button>
                            </div>

                            <form class="id-search" wire:submit="findById" role="search">
                                <label class="form-label" for="profile-id">{{ __('Search by Profile ID') }}</label>
                                <div class="id-search-row">
                                    <input type="search" class="form-control profile-select" id="profile-id" name="profile_id"
                                           placeholder="{{ __('e.g. OPM12370') }}" wire:model="profileId" autocomplete="off"
                                           @if ($idError) aria-invalid="true" aria-describedby="profile-id-error" @endif>
                                    <button type="submit" class="id-search-btn" aria-label="{{ __('Find profile by ID') }}">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                    </button>
                                </div>
                                @if ($idError)
                                    <p class="invalid-feedback d-block" id="profile-id-error" role="alert">{{ $idError }}</p>
                                @else
                                    <small class="text-muted-brand">{{ __('Know the ID? Go straight to the profile.') }}</small>
                                @endif
                            </form>

                            <p class="id-search-sep"><span>{{ __('or refine a search') }}</span></p>

                            <div class="search-form">
                                <div class="filter-row">
                                    <span class="form-label">{{ __("I'm looking for") }}</span>
                                    <p class="mb-0"><strong>{{ $lookingFor }}</strong></p>
                                </div>

                                <div class="row g-4">
                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="age-min">{{ __('Age') }}</label>
                                        <div class="range-row">
                                            <select class="form-select profile-select" id="age-min" name="age_min" wire:model.live="filters.age_min">
                                                <option value="">{{ __('Any') }}</option>
                                                @foreach ($ages as $age)<option value="{{ $age }}">{{ $age }}</option>@endforeach
                                            </select>
                                            <span class="range-sep">{{ __('to') }}</span>
                                            <select class="form-select profile-select" id="age-max" name="age_max" aria-label="{{ __('Maximum age') }}" wire:model.live="filters.age_max">
                                                <option value="">{{ __('Any') }}</option>
                                                @foreach ($ages as $age)<option value="{{ $age }}">{{ $age }}</option>@endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="height-min">{{ __('Height (cm)') }}</label>
                                        <div class="range-row">
                                            <input type="number" class="form-control profile-select" id="height-min" name="height_min" min="{{ $heightMin }}" max="{{ $heightMax }}" placeholder="{{ __('Min') }}" wire:model.live.debounce.400ms="filters.height_min">
                                            <span class="range-sep">{{ __('to') }}</span>
                                            <input type="number" class="form-control profile-select" id="height-max" name="height_max" min="{{ $heightMin }}" max="{{ $heightMax }}" placeholder="{{ __('Max') }}" aria-label="{{ __('Maximum height (cm)') }}" wire:model.live.debounce.400ms="filters.height_max">
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="religion">{{ __('Religion') }}</label>
                                        <select class="form-select profile-select" id="religion" name="religion" wire:model.live="filters.religion">
                                            <option value="">{{ __('Any') }}</option>
                                            @foreach ($religions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </div>

                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="caste">{{ __('Caste') }}</label>
                                        <select class="form-select profile-select search-multi" id="caste" name="caste[]" multiple size="4" wire:model.live="filters.caste" @disabled($castes === [])>
                                            @foreach ($castes as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <small class="text-muted-brand">{{ $castes === [] ? __('Choose a religion first.') : __('Hold Ctrl / Cmd to pick several.') }}</small>
                                        <div class="form-check mt-1">
                                            <input type="checkbox" class="form-check-input" id="caste-no-bar" name="caste_no_bar" value="1" wire:model.live="filters.caste_no_bar">
                                            <label class="form-check-label" for="caste-no-bar">{{ __('Also show "caste no bar" profiles') }}</label>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="district">{{ __('District') }}</label>
                                        <select class="form-select profile-select search-multi" id="district" name="district[]" multiple size="4" wire:model.live="filters.district">
                                            @foreach ($districts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </div>

                                    <div class="col-sm-6 col-lg-12">
                                        <label class="form-label" for="marital">{{ __('Marital status') }}</label>
                                        <select class="form-select profile-select search-multi" id="marital" name="marital[]" multiple size="3" wire:model.live="filters.marital">
                                            @foreach ($maritalStatuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </div>
                                </div>

                                <details class="more-filters mt-4" @if ($activeFilters > 6) open @endif>
                                    <summary class="more-filters-toggle">
                                        <i class="fa fa-sliders" aria-hidden="true"></i>
                                        <span class="more-filters-label">{{ __('More filters') }}</span>
                                    </summary>

                                    <div class="row g-4 more-filters-grid mt-1">
                                        @foreach ([
                                            ['mother_tongue', __('Mother tongue'), $motherTongues, true],
                                            ['star', __('Star'), $stars, true],
                                            ['rasi', __('Rasi'), $rasis, false],
                                            ['physical', __('Physical status'), $physicalStatuses, false],
                                            ['country', __('Living in (country)'), $countries, false],
                                            ['state', __('State'), $states, false],
                                            ['education_min', __('Education (at least)'), $education, false],
                                            ['occupation', __('Occupation'), $occupations, true],
                                            ['employer', __('Employed in'), $employerTypes, false],
                                            ['income_min', __('Annual income (at least)'), $incomeBands, false],
                                            ['family_status', __('Family status'), $options['family_status'], false],
                                            ['family_type', __('Family type'), $options['family_type'], false],
                                            ['family_values', __('Family values'), $options['family_values'], false],
                                            ['diet', __('Diet'), $options['diet'], false],
                                            ['smoking', __('Smoking'), $options['smoking'], false],
                                            ['drinking', __('Drinking'), $options['drinking'], false],
                                        ] as [$key, $label, $choices, $multi])
                                            @continue($key === 'state' && $choices === [])
                                            <div class="col-sm-6 col-lg-12" wire:key="filter-{{ $key }}">
                                                <label class="form-label" for="filter-{{ $key }}">{{ $label }}</label>
                                                @if ($multi)
                                                    <select class="form-select profile-select search-multi" id="filter-{{ $key }}" name="{{ $key }}[]" multiple size="4" wire:model.live="filters.{{ $key }}">
                                                        @foreach ($choices as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                                    </select>
                                                @else
                                                    <select class="form-select profile-select" id="filter-{{ $key }}" name="{{ $key }}" wire:model.live="filters.{{ $key }}">
                                                        <option value="">{{ __('Any') }}</option>
                                                        @foreach ($choices as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                                    </select>
                                                @endif
                                            </div>
                                        @endforeach

                                        <div class="col-sm-6 col-lg-12">
                                            <label class="form-label" for="filter-sub-caste">{{ __('Sub-caste') }}</label>
                                            <input type="text" class="form-control profile-select" id="filter-sub-caste" name="sub_caste" maxlength="60" wire:model.live.debounce.400ms="filters.sub_caste">
                                        </div>
                                        <div class="col-sm-6 col-lg-12">
                                            <label class="form-label" for="filter-citizenship">{{ __('Citizenship') }}</label>
                                            <input type="text" class="form-control profile-select" id="filter-citizenship" name="citizenship" maxlength="60" wire:model.live.debounce.400ms="filters.citizenship">
                                        </div>
                                        <div class="col-sm-6 col-lg-12">
                                            <label class="form-label" for="filter-active">{{ __('Active within') }}</label>
                                            <select class="form-select profile-select" id="filter-active" name="active" wire:model.live="filters.active">
                                                <option value="">{{ __('Any time') }}</option>
                                                <option value="1d">{{ __('1 day') }}</option>
                                                <option value="7d">{{ __('1 week') }}</option>
                                                <option value="30d">{{ __('1 month') }}</option>
                                            </select>
                                        </div>
                                        <div class="col-sm-6 col-lg-12">
                                            <label class="form-label" for="filter-created-by">{{ __('Profile created by') }}</label>
                                            <select class="form-select profile-select" id="filter-created-by" name="created_by" wire:model.live="filters.created_by">
                                                <option value="">{{ __('Anyone') }}</option>
                                                <option value="self">{{ __('The member') }}</option>
                                                <option value="family">{{ __('Family / relative') }}</option>
                                            </select>
                                        </div>

                                        <fieldset class="col-12 search-checks">
                                            <legend class="form-label">{{ __('Show only') }}</legend>
                                            @foreach ([
                                                'photo' => __('With photo'), 'verified' => __('ID verified'), 'premium' => __('Premium members'),
                                                'new' => __('Newly joined (7 days)'), 'nri' => __('NRI (living abroad)'), 'no_children' => __('No children'),
                                                'no_dosham' => __('No chovva dosham'), 'hide_viewed' => __('Hide profiles I\'ve viewed'),
                                            ] as $key => $label)
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" id="filter-{{ $key }}" name="{{ $key }}" value="1" wire:model.live="filters.{{ $key }}">
                                                    <label class="form-check-label" for="filter-{{ $key }}">{{ $label }}</label>
                                                </div>
                                            @endforeach
                                        </fieldset>
                                    </div>
                                </details>

                                <div class="filter-actions">
                                    <button type="button" class="filter-reset" wire:click="clearFilters">{{ __('Reset filters') }}</button>
                                </div>
                            </div>

                            <div class="search-sheet__foot d-lg-none">
                                <x-ui.button type="button" class="w-100" x-on:click="sheet = false">
                                    {{ trans_choice('Show :count profile|Show :count profiles', $total, ['count' => number_format($total)]) }}
                                </x-ui.button>
                            </div>
                        </section>
                    </div>
                </div>

                {{-- Results --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content search-results">
                        <section class="home-content">
                            <div class="content-header search-results__head">
                                <div class="content-title">
                                    <h1>{{ __('Search Results') }}</h1>
                                    <p class="mb-0" aria-live="polite">{{ trans_choice(':count profile|:count profiles', $total, ['count' => number_format($total)]) }}</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <x-ui.button type="button" variant="outline" size="sm" icon="fa-bookmark-o" wire:click="openSaveModal" aria-haspopup="dialog">{{ __('Save search') }}</x-ui.button>
                                    <div class="search-sort mb-0">
                                        <label class="visually-hidden" for="sort">{{ __('Sort by') }}</label>
                                        <select class="form-select profile-select" id="sort" name="sort" wire:model.live="filters.sort">
                                            @foreach ($sorts as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                            @if ($saveSuccess)
                                <x-ui.alert type="success">{{ $saveSuccess }}</x-ui.alert>
                            @endif

                            <x-ui.button type="button" variant="outline" icon="fa-sliders" id="open-filters" class="w-100 mb-3 d-lg-none" x-on:click="sheet = true" aria-controls="search-filters" x-bind:aria-expanded="sheet">
                                {{ $activeFilters > 0 ? trans_choice('Filters (:count)|Filters (:count)', $activeFilters, ['count' => $activeFilters]) : __('Filters') }}
                            </x-ui.button>

                            @if ($notice)
                                <x-ui.alert type="warning">{{ $notice }}</x-ui.alert>
                            @endif

                            <div wire:loading.flex wire:target="filters,clearFilters" class="search-loading" aria-hidden="true">
                                <x-ui.skeleton shape="row" :count="3" class="w-100" />
                            </div>

                            <div class="profile-grid" wire:loading.remove wire:target="filters,clearFilters">
                                @forelse ($cards as $card)
                                    <x-profile.row :profile="$card" wire:key="result-{{ $card->code }}" />
                                @empty
                                    <x-ui.empty-state icon="fa-search" :title="__('No profiles match')"
                                                      :message="__('Try fewer filters, a wider age range or another district.')" />
                                @endforelse
                            </div>

                            @if ($cursor)
                                <div class="content-btn" x-intersect.margin.200px="$wire.loadMore()">
                                    <button type="button" class="view-btn" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                                        <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                                        <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                                    </button>
                                </div>
                            @endif
                        </section>
                    </div>
                </div>

                {{-- Ad rail --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>

    <div class="search-sheet__backdrop d-lg-none" x-show="sheet" x-cloak x-on:click="sheet = false"></div>

    {{-- Save this search (M04): name + alert frequency; the current filters are saved. --}}
    <x-ui.modal name="save-search" :title="__('Save this search')">
        <form id="save-search-form" wire:submit="saveSearch" novalidate>
            <x-ui.input :label="__('Name')" name="save_name" id="save-name" wire:model="saveName" maxlength="60" required autofocus
                        :hint="__('So you can recognise it later.')" />
            <x-ui.select :label="__('Email alerts')" name="save_frequency" id="save-frequency" wire:model="saveFrequency"
                         :options="\App\Enums\AlertFrequency::options()" :hint="__('We email you when new profiles match.')" />
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button form="save-search-form" loading="saveSearch">{{ __('Save search') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
