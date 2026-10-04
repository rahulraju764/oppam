{{--
    /profiles — All Profiles (M04, template all-profiles.php: 3/6/3 dashboard row — browse menu and
    saved searches on the left, results in the middle, ad rail on the right). On phones the left
    rail is a bottom sheet opened from the "Browse" button, built inside this component like /search
    (the template's mobile-rail drawer moves DOM out of the Livewire root).
--}}
<div x-data="{ sheet: false }" x-on:keydown.escape.window="sheet = false"
     x-effect="document.body.classList.toggle('search-sheet-open', sheet)"
     x-on:livewire:navigating.window="document.body.classList.remove('search-sheet-open')">
    <section class="dashboard-section matches-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Left rail: browse menu + saved searches (bottom sheet on phones) --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="search-sheet browse-rail" id="browse-rail" x-bind:class="{ 'is-open': sheet }" x-trap.noscroll="sheet"
                         role="region" aria-label="{{ __('Browse') }}" x-bind:role="sheet ? 'dialog' : 'region'" x-bind:aria-modal="sheet ? 'true' : null">
                        <div class="search-sheet__head d-lg-none">
                            <h2 class="h5 mb-0">{{ __('Browse') }}</h2>
                            <button type="button" class="search-sheet__close" x-on:click="sheet = false" aria-label="{{ __('Close') }}">
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>

                        <nav class="side-bar" aria-label="{{ __('Match categories') }}">
                            <p class="sidebar-section-title">{{ __('Browse') }}</p>
                            <a href="{{ route('member.profiles') }}" class="nav-link-custom active" aria-current="page" wire:navigate>
                                <span class="nav-link-label"><i class="fa fa-users" aria-hidden="true"></i> {{ __('All Profiles') }}</span>
                                <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                            </a>
                            <a href="{{ route('member.matches.daily') }}" class="nav-link-custom" wire:navigate>
                                <span class="nav-link-label"><i class="fa fa-bolt" aria-hidden="true"></i> {{ __('Daily Matches') }}</span>
                                <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                            </a>
                            <a href="{{ route('member.search') }}" class="nav-link-custom" wire:navigate>
                                <span class="nav-link-label"><i class="fa fa-search" aria-hidden="true"></i> {{ __('Advanced Search') }}</span>
                                <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                            </a>

                            <div class="sidebar-divider"></div>

                            <p class="sidebar-section-title" id="saved-searches-title">
                                {{ __('Saved Searches') }} <span class="saved-search__count">({{ $savedSearches->count() }}/{{ $maxSaved }})</span>
                            </p>
                        </nav>

                        <section class="saved-searches" aria-labelledby="saved-searches-title">
                            @forelse ($savedSearches as $saved)
                                <div class="saved-search" wire:key="saved-{{ $saved->id }}">
                                    <a href="{{ $saved->searchUrl() }}" class="saved-search__name" wire:navigate>{{ $saved->name }}</a>
                                    <div class="saved-search__controls">
                                        <label class="visually-hidden" for="alerts-{{ $loop->index }}">{{ __('Email alerts for :name', ['name' => $saved->name]) }}</label>
                                        <select class="form-select form-select-sm" id="alerts-{{ $loop->index }}" name="alerts_{{ $loop->index }}"
                                                wire:change="updateFrequency('{{ $saved->id }}', $event.target.value)">
                                            @foreach ($frequencies as $value => $label)
                                                <option value="{{ $value }}" @selected($saved->alert_frequency->value === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="saved-search__action" wire:click="startRename('{{ $saved->id }}')"
                                                aria-haspopup="dialog" aria-label="{{ __('Rename :name', ['name' => $saved->name]) }}">
                                            <i class="fa fa-pencil" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="saved-search__action saved-search__action--danger"
                                                wire:click="deleteSavedSearch('{{ $saved->id }}')"
                                                wire:confirm="{{ __('Delete the saved search “:name”?', ['name' => $saved->name]) }}"
                                                aria-label="{{ __('Delete :name', ['name' => $saved->name]) }}">
                                            <i class="fa fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <p class="saved-search__empty">{{ __('No saved searches yet. Set filters on Advanced Search and choose “Save search”.') }}</p>
                                <a href="{{ route('member.search') }}" class="saved-search__empty-link" wire:navigate>{{ __('Open Advanced Search') }}</a>
                            @endforelse
                        </section>
                    </div>
                </div>

                {{-- Results --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content match-results">
                        <section class="home-content">
                            <div class="content-header search-results__head">
                                <div class="content-title">
                                    <h1>{{ __('All Profiles') }}</h1>
                                    <p class="mb-0" aria-live="polite">{{ trans_choice(':count profile|:count profiles', $total, ['count' => number_format($total)]) }}</p>
                                </div>
                                <div class="search-sort mb-0">
                                    <label class="visually-hidden" for="sort">{{ __('Sort by') }}</label>
                                    <select class="form-select profile-select" id="sort" name="sort" wire:model.live="sort">
                                        @foreach ($sorts as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                            </div>

                            <x-ui.button type="button" variant="outline" icon="fa-bars" id="open-browse" class="w-100 mb-3 d-lg-none" x-on:click="sheet = true"
                                         aria-controls="browse-rail" x-bind:aria-expanded="sheet">{{ __('Browse & saved searches') }}</x-ui.button>

                            @if ($notice)
                                <x-ui.alert type="info">{{ $notice }}</x-ui.alert>
                            @endif

                            <div wire:loading.flex wire:target="sort" class="search-loading" aria-hidden="true">
                                <x-ui.skeleton shape="row" :count="3" class="w-100" />
                            </div>

                            <div class="profile-grid" wire:loading.remove wire:target="sort">
                                @forelse ($cards as $card)
                                    <x-profile.row :profile="$card" wire:key="all-{{ $card->code }}" />
                                @empty
                                    <x-ui.empty-state icon="fa-users" :title="__('No profiles yet')"
                                                      :message="__('New members join every day. Try Advanced Search with wider filters.')">
                                        <x-ui.button :href="route('member.search')" wire:navigate>{{ __('Open Advanced Search') }}</x-ui.button>
                                    </x-ui.empty-state>
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

    {{-- Rename a saved search --}}
    <x-ui.modal name="rename-search" :title="__('Rename saved search')">
        <form id="rename-search-form" wire:submit="rename" novalidate>
            <x-ui.input :label="__('Name')" name="rename_to" id="rename-to" wire:model="renameTo" maxlength="60" required autofocus />
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button form="rename-search-form" loading="rename">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
