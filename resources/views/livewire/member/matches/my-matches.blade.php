{{--
    /matches — My Matches (M05, template my-matches.php: 3/6/3 row — funnel tabs on the left, the
    2 × 2 funnel counters and results in the middle, ad rail on the right). On phones the left rail
    is a bottom sheet built in the component (like /search; mobile-rail.js would move it out of
    the Livewire root). Counters and tabs are buttons that switch the tab (?tab= in the URL).
--}}
<div x-data="{ sheet: false }" x-on:keydown.escape.window="sheet = false"
     x-effect="document.body.classList.toggle('search-sheet-open', sheet)"
     x-on:livewire:navigating.window="document.body.classList.remove('search-sheet-open')">
    <section class="dashboard-section myhome-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Left rail: every tab + browse links (bottom sheet on phones) --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="search-sheet browse-rail" id="matches-rail" x-bind:class="{ 'is-open': sheet }" x-trap.noscroll="sheet"
                         role="region" aria-label="{{ __('Match categories') }}" x-bind:role="sheet ? 'dialog' : 'region'" x-bind:aria-modal="sheet ? 'true' : null">
                        <div class="search-sheet__head d-lg-none">
                            <h2 class="h5 mb-0">{{ __('My Matches') }}</h2>
                            <button type="button" class="search-sheet__close" x-on:click="sheet = false" aria-label="{{ __('Close') }}">
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>

                        <nav class="side-bar" aria-label="{{ __('Match categories') }}">
                            <p class="sidebar-section-title">{{ __('My Matches') }}</p>
                            @foreach ($tabs as $item)
                                <button type="button" class="nav-link-custom nav-link-button @if ($item === $current) active @endif"
                                        wire:click="show('{{ $item->value }}')" x-on:click="sheet = false"
                                        aria-pressed="{{ $item === $current ? 'true' : 'false' }}">
                                    <span class="nav-link-label"><i class="fa {{ $item->icon() }}" aria-hidden="true"></i> {{ $item->label() }}</span>
                                    <span class="nav-count">{{ number_format($counts[$item->value] ?? 0) }}</span>
                                </button>
                            @endforeach

                            <div class="sidebar-divider"></div>

                            <p class="sidebar-section-title">{{ __('Browse') }}</p>
                            <a href="{{ route('member.profiles') }}" class="nav-link-custom" wire:navigate>
                                <span class="nav-link-label"><i class="fa fa-users" aria-hidden="true"></i> {{ __('All Profiles') }}</span>
                                <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                            </a>
                            <a href="{{ route('member.matches.daily') }}" class="nav-link-custom" wire:navigate>
                                <span class="nav-link-label"><i class="fa fa-bolt" aria-hidden="true"></i> {{ __('Daily Matches') }}</span>
                                <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
                            </a>
                        </nav>
                    </div>
                </div>

                {{-- Centre: funnel counters + results --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="counter-section">
                        <div class="counter-header">
                            <h2>{{ __('My Matches') }}</h2>
                            <span class="counter-header-meta">{{ __('Your match funnel') }}</span>
                        </div>
                        <div class="row">
                            @foreach ($funnel as $item)
                                <div class="col-lg-6 col-md-3 col-sm-3 col-6">
                                    <button type="button" class="counter-sec counter-button @if ($item === $current) active @endif"
                                            wire:click="show('{{ $item->value }}')" aria-pressed="{{ $item === $current ? 'true' : 'false' }}">
                                        <span class="counter-no">
                                            <span class="counter-value">{{ number_format($counts[$item->value] ?? 0) }}</span>
                                            <span class="counter-label">{{ $item->label() }}</span>
                                        </span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="dashboard-content match-results">
                        <section class="home-content">
                            <div class="content-header search-results__head">
                                <div class="content-title">
                                    <h1>{{ $current->label() }}</h1>
                                    <p class="mb-0" aria-live="polite">{{ trans_choice(':count profile|:count profiles', $total, ['count' => number_format($total)]) }}</p>
                                </div>
                            </div>

                            <x-ui.button type="button" variant="outline" icon="fa-bars" id="open-matches-rail" class="w-100 mb-3 d-lg-none"
                                         x-on:click="sheet = true" aria-controls="matches-rail" x-bind:aria-expanded="sheet">{{ __('All match categories') }}</x-ui.button>

                            @if ($notice)
                                <x-ui.alert type="info">{{ $notice }}</x-ui.alert>
                            @endif

                            <div wire:loading.flex wire:target="show,tab" class="search-loading" aria-hidden="true">
                                <x-ui.skeleton shape="row" :count="3" class="w-100" />
                            </div>

                            <div class="profile-grid" wire:loading.remove wire:target="show,tab">
                                @forelse ($cards as $card)
                                    <x-profile.row :profile="$card" wire:key="match-{{ $card->code }}" />
                                @empty
                                    <x-ui.empty-state icon="fa-heart-o" :title="__('No matches here yet')"
                                                      :message="__('New members join every day. Widening your partner preferences brings more matches.')">
                                        <x-ui.button :href="route('member.profiles')" wire:navigate>{{ __('Browse all profiles') }}</x-ui.button>
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
</div>
