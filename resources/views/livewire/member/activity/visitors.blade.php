<div>
    <main id="main" tabindex="-1">
        <h1 class="visually-hidden">{{ __('Profile Visitors') }}</h1>

        <section class="visitors-section bg-coloring py-4">
            <div class="container">
                <div class="row">

                    {{-- Page Header --}}
                    <div class="col-12 mb-4">
                        <h1 class="h2 fw-bold text-dark mb-1">{{ __('Profile Visitors & Views') }}</h1>
                        <p class="text-muted mb-0">
                            {{ __('Track who viewed your profile and browse your 90-day viewing history.') }}
                        </p>
                    </div>

                    {{-- Main Content Column (9 cols) --}}
                    <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                        {{-- Tabs Navigation --}}
                        <div class="d-flex align-items-center gap-2 mb-4 border-bottom pb-2">
                            <button type="button"
                                    wire:click="setTab('who_viewed_me')"
                                    class="btn {{ $tab === 'who_viewed_me' ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill px-4 py-2 d-flex align-items-center gap-2">
                                <i class="fa fa-eye" aria-hidden="true"></i>
                                <span>{{ __('Who Viewed Me') }}</span>
                                <span class="badge {{ $tab === 'who_viewed_me' ? 'bg-white text-primary' : 'bg-secondary text-white' }} rounded-pill">
                                    {{ number_format($whoViewedCount) }}
                                </span>
                            </button>

                            <button type="button"
                                    wire:click="setTab('viewed_by_me')"
                                    class="btn {{ $tab === 'viewed_by_me' ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill px-4 py-2 d-flex align-items-center gap-2">
                                <i class="fa fa-history" aria-hidden="true"></i>
                                <span>{{ __('Profiles I Viewed') }}</span>
                                <span class="badge {{ $tab === 'viewed_by_me' ? 'bg-white text-primary' : 'bg-secondary text-white' }} rounded-pill">
                                    {{ number_format($viewedByMeCount) }}
                                </span>
                            </button>
                        </div>

                        {{-- Tab 1: Who Viewed Me --}}
                        @if ($tab === 'who_viewed_me')
                            @if ($canSeeVisitors)
                                {{-- Gold/Diamond Plan: Full Unlocked List --}}
                                @if (count($items) > 0)
                                    <div class="profile-grid">
                                        @foreach ($items as $item)
                                            @php
                                                $view = $item['view'];
                                                $card = $item['card'];
                                            @endphp
                                            <div wire:key="viewer-{{ $view->id }}">
                                                <x-profile.row :profile="$card">
                                                    <x-slot:actions>
                                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 w-100">
                                                            <span class="small text-muted">
                                                                <i class="fa fa-clock-o text-primary" aria-hidden="true"></i>
                                                                {{ __('Viewed :time', ['time' => $view->updated_at->diffForHumans()]) }}
                                                                @if ($view->count > 1)
                                                                    <span class="badge bg-light text-secondary ms-1">
                                                                        {{ __(':count times', ['count' => $view->count]) }}
                                                                    </span>
                                                                @endif
                                                            </span>
                                                            <a href="{{ route('member.profile.show', $card->code) }}"
                                                               class="btn btn-sm btn-primary"
                                                               wire:navigate>
                                                                {{ __('View Profile') }}
                                                            </a>
                                                        </div>
                                                    </x-slot:actions>
                                                </x-profile.row>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($paginator && $paginator->hasPages())
                                        <div class="mt-4">
                                            {{ $paginator->links() }}
                                        </div>
                                    @endif
                                @else
                                    <div class="bg-white rounded-3 border p-5 text-center shadow-sm">
                                        <i class="fa fa-eye fa-3x text-muted mb-3" aria-hidden="true"></i>
                                        <h3 class="h5 fw-bold text-dark mb-2">{{ __('No visitors recorded yet') }}</h3>
                                        <p class="text-muted mb-4">
                                            {{ __('Your profile has not been visited recently. Updating your photos and preferences will help you get noticed!') }}
                                        </p>
                                        <a href="{{ route('member.all-profiles') }}" class="btn btn-primary" wire:navigate>
                                            {{ __('Explore Profiles') }}
                                        </a>
                                    </div>
                                @endif
                            @else
                                {{-- Free / Silver Plan: Count + Blurred Teaser + Upgrade Banner (PRD §10 M15) --}}
                                <div class="bg-white rounded-3 border p-4 shadow-sm mb-4">
                                    {{-- Banner & Upgrade CTA --}}
                                    <div class="text-center py-4 px-3 bg-light rounded-3 border mb-4">
                                        <div class="mb-3">
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning p-3" style="width: 64px; height: 64px;">
                                                <i class="fa fa-lock fa-2x" aria-hidden="true"></i>
                                            </span>
                                        </div>
                                        <h3 class="h4 fw-bold text-dark mb-2">
                                            {{ __(':count Members Viewed Your Profile', ['count' => number_format($whoViewedCount)]) }}
                                        </h3>
                                        <p class="text-muted mx-auto mb-4" style="max-width: 520px;">
                                            {{ __('Members with Gold or Diamond membership can see exactly who viewed them, including full profiles, photos, and visit timestamps.') }}
                                        </p>
                                        <a href="{{ route('plans') }}" class="btn btn-warning btn-lg fw-bold px-4" wire:navigate>
                                            <i class="fa fa-diamond me-2" aria-hidden="true"></i> {{ __('Upgrade to Gold to See Visitors') }}
                                        </a>
                                    </div>

                                    {{-- Blurred Teasers --}}
                                    <div class="teaser-container position-relative">
                                        <h4 class="h6 fw-bold text-muted text-uppercase mb-3">
                                            {{ __('Recent Visitors (Preview)') }}
                                        </h4>

                                        <div class="d-flex flex-column gap-3" style="filter: blur(4px); pointer-events: none; user-select: none; opacity: 0.6;">
                                            @for ($i = 0; $i < min(max($whoViewedCount, 3), 4); $i++)
                                                <div class="d-flex align-items-center p-3 bg-light rounded-3 border">
                                                    <div class="rounded-circle bg-secondary me-3" style="width: 50px; height: 50px;"></div>
                                                    <div class="flex-grow-1">
                                                        <div class="h6 mb-1 fw-bold text-dark">Member OPM100{{ $i + 1 }} ••••</div>
                                                        <div class="small text-muted">26 yrs, 5'4" • Engineer • Ernakulam</div>
                                                    </div>
                                                    <div class="small text-muted">{{ __('Viewed recently') }}</div>
                                                </div>
                                            @endfor
                                        </div>
                                    </div>
                                </div>
                            @endif

                        {{-- Tab 2: Profiles I Viewed --}}
                        @else
                            @if (count($items) > 0)
                                <div class="profile-grid">
                                    @foreach ($items as $item)
                                        @php
                                            $view = $item['view'];
                                            $card = $item['card'];
                                        @endphp
                                        <div wire:key="viewed-{{ $view->id }}">
                                            <x-profile.row :profile="$card">
                                                <x-slot:actions>
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 w-100">
                                                        <span class="small text-muted">
                                                            <i class="fa fa-history text-secondary" aria-hidden="true"></i>
                                                            {{ __('You visited :time', ['time' => $view->updated_at->diffForHumans()]) }}
                                                        </span>
                                                        <a href="{{ route('member.profile.show', $card->code) }}"
                                                           class="btn btn-sm btn-primary"
                                                           wire:navigate>
                                                            {{ __('View Profile Again') }}
                                                        </a>
                                                    </div>
                                                </x-slot:actions>
                                            </x-profile.row>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($paginator && $paginator->hasPages())
                                    <div class="mt-4">
                                        {{ $paginator->links() }}
                                    </div>
                                @endif
                            @else
                                <div class="bg-white rounded-3 border p-5 text-center shadow-sm">
                                    <i class="fa fa-history fa-3x text-muted mb-3" aria-hidden="true"></i>
                                    <h3 class="h5 fw-bold text-dark mb-2">{{ __('No profile views in the last 90 days') }}</h3>
                                    <p class="text-muted mb-4">
                                        {{ __('When you inspect member profiles, your 90-day history will appear here for easy reference.') }}
                                    </p>
                                    <a href="{{ route('member.all-profiles') }}" class="btn btn-primary" wire:navigate>
                                        {{ __('Browse Profiles') }}
                                    </a>
                                </div>
                            @endif
                        @endif
                    </div>

                    {{-- Sidebar Column (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <div class="rail-stack d-flex flex-column gap-3">
                            {{-- Info Widget --}}
                            <div class="bg-white rounded-3 border p-3 shadow-sm">
                                <h4 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                                    <i class="fa fa-shield text-primary" aria-hidden="true"></i>
                                    {{ __('Privacy & Visitors') }}
                                </h4>
                                <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-2">
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('Incognito viewers do not leave records in visitor logs.') }}</span>
                                    </li>
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('Viewing history is kept for 90 days.') }}</span>
                                    </li>
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('Gold & Diamond members enjoy unlimited visibility of who viewed them.') }}</span>
                                    </li>
                                </ul>
                            </div>

                            {{-- Ads / Promo --}}
                            <x-ads.rail />
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </main>
</div>
