<div>
    <main id="main" tabindex="-1">
        <h1 class="visually-hidden">{{ __('My Matches') }}</h1>

        <section class="dashboard-section myhome-section" data-mobile-rail data-rail-label="My Matches">
            <div class="container">
                <div class="row dashboard-row">

                    {{-- LEFT RAIL: Match Funnel Categories (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <div class="rail-stack">
                            <nav class="side-bar" aria-label="{{ __('Match categories') }}" data-rail="menu">
                                <p class="sidebar-section-title">{{ __('My Matches') }}</p>

                                @foreach ($funnelTabs as $key => $tabData)
                                    <button type="button"
                                            wire:click="$set('tab', '{{ $key }}')"
                                            class="nav-link-custom w-100 text-start border-0 bg-transparent {{ $tab === $key ? 'active' : '' }}"
                                            {{ $tab === $key ? 'aria-current="true"' : '' }}>
                                        <span class="nav-link-label">
                                            @if ($key === 'all')
                                                <i class="fa fa-users" aria-hidden="true"></i>
                                            @elseif ($key === 'new')
                                                <i class="fa fa-star" aria-hidden="true"></i>
                                            @elseif ($key === 'unviewed')
                                                <i class="fa fa-eye-slash" aria-hidden="true"></i>
                                            @elseif ($key === 'viewed')
                                                <i class="fa fa-eye" aria-hidden="true"></i>
                                            @elseif ($key === 'near_me')
                                                <i class="fa fa-map-marker" aria-hidden="true"></i>
                                            @elseif ($key === 'premium')
                                                <i class="fa fa-diamond" aria-hidden="true"></i>
                                            @else
                                                <i class="fa fa-heart" aria-hidden="true"></i>
                                            @endif
                                            {{ $tabData['label'] }}
                                        </span>
                                        <span class="nav-count">{{ number_format($tabData['count']) }}</span>
                                    </button>
                                @endforeach

                                <div class="sidebar-divider"></div>

                                <p class="sidebar-section-title">{{ __('Browse') }}</p>

                                <a href="{{ route('member.all-profiles') }}" class="nav-link-custom" wire:navigate>
                                    <span class="nav-link-label">
                                        <i class="fa fa-users" aria-hidden="true"></i>
                                        {{ __('All Profiles') }}
                                    </span>
                                    <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                                </a>

                                @if (\Illuminate\Support\Facades\Route::has('member.daily-matches'))
                                    <a href="{{ route('member.daily-matches') }}" class="nav-link-custom" wire:navigate>
                                        <span class="nav-link-label">
                                            <i class="fa fa-bolt" aria-hidden="true"></i>
                                            {{ __('Daily Matches') }}
                                        </span>
                                        <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                                    </a>
                                @endif
                            </nav>
                        </div>
                    </div>

                    {{-- CENTRE COLUMN: Funnel Counters + Results (6 cols) --}}
                    <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                        {{-- 2x2 Funnel Counter Box --}}
                        <div class="counter-section mb-4">
                            <div class="counter-header">
                                <h2>{{ __('My Matches') }}</h2>
                                <span class="counter-header-meta">{{ __('Your personal match funnel') }}</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6 col-12">
                                    <div class="counter-sec {{ $tab === 'all' ? 'active' : '' }}" style="cursor: pointer;" wire:click="$set('tab', 'all')">
                                        <div class="counter-no">
                                            <span class="counter-value">{{ number_format($counts['all']) }}</span>
                                            <span class="counter-label">{{ __('All Matches') }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-12">
                                    <div class="counter-sec {{ $tab === 'unviewed' ? 'active' : '' }}" style="cursor: pointer;" wire:click="$set('tab', 'unviewed')">
                                        <div class="counter-no">
                                            <span class="counter-value">{{ number_format($counts['unviewed']) }}</span>
                                            <span class="counter-label">{{ __('Yet to be Viewed') }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-12">
                                    <div class="counter-sec {{ $tab === 'viewed' ? 'active' : '' }}" style="cursor: pointer;" wire:click="$set('tab', 'viewed')">
                                        <div class="counter-no">
                                            <span class="counter-value">{{ number_format($counts['viewed']) }}</span>
                                            <span class="counter-label">{{ __('Viewed') }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-12">
                                    <div class="counter-sec {{ $tab === 'near_me' ? 'active' : '' }}" style="cursor: pointer;" wire:click="$set('tab', 'near_me')">
                                        <div class="counter-no">
                                            <span class="counter-value">{{ number_format($counts['near_me']) }}</span>
                                            <span class="counter-label">{{ __('Near Me') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Results Header --}}
                        <div class="dashboard-content match-results">
                            <section class="home-content">
                                <div class="content-header d-flex justify-content-between align-items-center">
                                    <div class="content-title">
                                        <h2>{{ $funnelTabs[$tab]['label'] ?? __('Matches') }}</h2>
                                        <p>{{ __(':count profiles available', ['count' => number_format($total)]) }}</p>
                                    </div>
                                </div>

                                <div class="profile-grid">
                                    @forelse ($cards as $card)
                                        <x-profile.row :profile="$card" wire:key="match-{{ $card->code }}" />
                                    @empty
                                        <div class="empty-state text-center py-5">
                                            <i class="fa fa-heart-o fa-3x text-muted mb-3"></i>
                                            <h3>{{ __('No matches in this category') }}</h3>
                                            <p class="text-muted">{{ __('Try browsing other tabs or update your partner preferences.') }}</p>
                                            <a href="{{ route('member.profile.me') }}#partner-preferences" class="btn btn-primary mt-2" wire:navigate>
                                                {{ __('Edit Partner Preferences') }}
                                            </a>
                                        </div>
                                    @endforelse
                                </div>

                                @if ($cursor !== null)
                                    <div class="text-center py-4" x-data x-intersect="$wire.loadMore()">
                                        <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="btn btn-outline-secondary">
                                            <span wire:loading.remove wire:target="loadMore">{{ __('Load more profiles') }}</span>
                                            <span wire:loading wire:target="loadMore"><i class="fa fa-spinner fa-spin"></i> {{ __('Loading...') }}</span>
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
</div>
