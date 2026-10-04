<div>
    <main id="main" tabindex="-1">
        <section class="dashboard-section matches-section" data-mobile-rail data-rail-label="Browse">
            <div class="container">
                <div class="row dashboard-row">

                    {{-- LEFT RAIL: Browse Navigation (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <nav class="side-bar" aria-label="{{ __('Match categories') }}" data-rail="menu">
                            <p class="sidebar-section-title">{{ __('Browse') }}</p>

                            <a href="{{ route('member.all-profiles') }}" class="nav-link-custom active" aria-current="true" wire:navigate>
                                <span class="nav-link-label">
                                    <i class="fa fa-users" aria-hidden="true"></i>
                                    {{ __('All Profiles') }}
                                </span>
                                <span class="nav-count">
                                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                                </span>
                            </a>

                            @if (\Illuminate\Support\Facades\Route::has('member.daily-matches'))
                                <a href="{{ route('member.daily-matches') }}" class="nav-link-custom" wire:navigate>
                                    <span class="nav-link-label">
                                        <i class="fa fa-bolt" aria-hidden="true"></i>
                                        {{ __('Daily Matches') }}
                                    </span>
                                    <span class="nav-count">
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </span>
                                </a>
                            @endif

                            <div class="sidebar-divider"></div>

                            <p class="sidebar-section-title">{{ __('Based on Activity') }}</p>

                            @if (\Illuminate\Support\Facades\Route::has('member.my-matches'))
                                <a href="{{ route('member.my-matches') }}" class="nav-link-custom" wire:navigate>
                                    <span class="nav-link-label">
                                        <i class="fa fa-heart" aria-hidden="true"></i>
                                        {{ __('My Matches') }}
                                    </span>
                                    <span class="nav-count">
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </span>
                                </a>
                            @endif

                            @if (\Illuminate\Support\Facades\Route::has('member.visitors'))
                                <a href="{{ route('member.visitors') }}" class="nav-link-custom" wire:navigate>
                                    <span class="nav-link-label">
                                        <i class="fa fa-eye" aria-hidden="true"></i>
                                        {{ __('Viewed You') }}
                                    </span>
                                    <span class="nav-count">
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </span>
                                </a>
                            @endif

                            <div class="sidebar-divider"></div>

                            <p class="sidebar-section-title">{{ __('Saved Searches') }}</p>
                            <button type="button" wire:click="toggleSavedList" class="nav-link-custom text-start w-100 bg-transparent border-0">
                                <span class="nav-link-label">
                                    <i class="fa fa-bookmark" aria-hidden="true"></i>
                                    {{ __('Manage Saved') }} ({{ $savedSearches->count() }})
                                </span>
                                <span class="nav-count">
                                    <i class="fa fa-angle-{{ $showSavedList ? 'down' : 'right' }}" aria-hidden="true"></i>
                                </span>
                            </button>

                            @if ($showSavedList)
                                <div class="p-2 border-top">
                                    @forelse ($savedSearches as $saved)
                                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom small">
                                            <div>
                                                <a href="{{ route('member.search', ['f' => $saved->filters]) }}" class="fw-semibold text-truncate d-inline-block" style="max-width: 140px;">
                                                    {{ $saved->name }}
                                                </a>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    {{ $saved->alert_frequency->label() }}
                                                </div>
                                            </div>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" wire:click="deleteSavedSearch('{{ $saved->id }}')" class="btn btn-outline-danger btn-sm p-1" title="{{ __('Delete') }}">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-muted small my-2">{{ __('No saved searches yet.') }}</p>
                                    @endforelse
                                </div>
                            @endif
                        </nav>
                    </div>

                    {{-- CENTRE: Results (6 cols) --}}
                    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                        <div class="dashboard-content match-results">
                            <section class="home-content">

                                @if ($saveSuccess)
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        {{ $saveSuccess }}
                                        <button type="button" class="btn-close" wire:click="$set('saveSuccess', null)" aria-label="{{ __('Close') }}"></button>
                                    </div>
                                @endif

                                <div class="content-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="content-title">
                                        <h1>{{ __('All Profiles') }}</h1>
                                        <p>{{ __(':count profiles based on your partner preferences', ['count' => number_format($total)]) }}</p>
                                    </div>

                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" wire:click="openSaveModal" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1">
                                            <i class="fa fa-bookmark-o"></i>
                                            <span>{{ __('Save Search') }}</span>
                                        </button>

                                        <div class="sort-by mb-0">
                                            <label class="form-label visually-hidden" for="sort">{{ __('Sort by') }}</label>
                                            <select wire:model.live="sort" class="form-select profile-select" id="sort" name="sort">
                                                @foreach ($sorts as $val => $label)
                                                    <option value="{{ $val }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="profile-grid">
                                    @forelse ($cards as $card)
                                        <x-profile.row :profile="$card" wire:key="all-{{ $card->code }}" />
                                    @empty
                                        <div class="empty-state text-center py-5">
                                            <i class="fa fa-users fa-3x text-muted mb-3"></i>
                                            <h3>{{ __('No profiles found') }}</h3>
                                            <p class="text-muted">{{ __('Try adjusting your preferences or browse other categories.') }}</p>
                                            <a href="{{ route('member.search') }}" class="btn btn-primary mt-2">
                                                {{ __('Open Advanced Search') }}
                                            </a>
                                        </div>
                                    @endforelse
                                </div>

                                @if ($cursor !== null)
                                    <div class="text-center py-4" x-data x-intersect="$wire.loadMore()">
                                        <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="btn btn-outline-secondary">
                                            <span wire:loading.remove wire:target="loadMore">{{ __('Load more profiles') }}</span>
                                            <span wire:loading wire:target="loadMore">
                                                <i class="fa fa-spinner fa-spin"></i> {{ __('Loading...') }}
                                            </span>
                                        </button>
                                    </div>
                                @endif

                            </section>
                        </div>
                    </div>

                    {{-- RIGHT RAIL: Ads (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <x-ads.rail />
                    </div>

                </div>
            </div>
        </section>
    </main>

    {{-- Save Search Modal --}}
    @if ($showSaveModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Save this Search') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeSaveModal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <form wire:submit="saveSearch">
                        <div class="modal-body">
                            @if ($saveError)
                                <div class="alert alert-danger" role="alert">
                                    {{ $saveError }}
                                </div>
                            @endif

                            <div class="mb-3">
                                <label for="saveName" class="form-label">{{ __('Search Name') }}</label>
                                <input type="text" id="saveName" wire:model="saveName" class="form-control" maxlength="60" required autofocus>
                                <div class="form-text">{{ __('Give this search a name to recognize it later.') }}</div>
                            </div>

                            <div class="mb-3">
                                <label for="saveFrequency" class="form-label">{{ __('Email Alerts') }}</label>
                                <select id="saveFrequency" wire:model="saveFrequency" class="form-select">
                                    @foreach ($frequencies as $val => $lbl)
                                        <option value="{{ $val }}">{{ $lbl }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Receive email updates when new profiles match this search.') }}</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeSaveModal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="saveSearch">{{ __('Save Search') }}</span>
                                <span wire:loading wire:target="saveSearch"><i class="fa fa-spinner fa-spin"></i> {{ __('Saving...') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
