<div>
    <main id="main" tabindex="-1">
        <h1 class="visually-hidden">{{ __('Daily Matches') }}</h1>

        <section class="daily-section bg-coloring py-4">
            <div class="container">
                <div class="row">

                    {{-- Page Header --}}
                    <div class="col-12 mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h1 class="h2 fw-bold text-dark mb-1">{{ __('Daily Matches') }}</h1>
                                <p class="text-muted mb-0">
                                    {{ __("Today's hand-picked recommendations based on your preferences. Updated daily at 05:00 IST.") }}
                                </p>
                            </div>

                            {{-- Midnight IST Countdown Timer --}}
                            <div x-data="{
                                    expiresAt: new Date('{{ $expiresAt }}').getTime(),
                                    remaining: '',
                                    updateCountdown() {
                                        const now = new Date().getTime();
                                        const diff = Math.max(0, this.expiresAt - now);
                                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                                        this.remaining = `${hours}h ${minutes}m ${seconds}s`;
                                    }
                                 }"
                                 x-init="updateCountdown(); setInterval(() => updateCountdown(), 1000)"
                                 class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-white border shadow-sm">
                                <i class="fa fa-clock-o text-danger" aria-hidden="true"></i>
                                <span class="small text-muted">{{ __('Batch expires in:') }}</span>
                                <strong class="small text-danger fw-bold" x-text="remaining">--:--:--</strong>
                            </div>
                        </div>
                    </div>

                    {{-- Main Content Column (9 cols) --}}
                    <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                        @if ($matchCount > 0)
                            <div class="profile-grid">
                                @foreach ($matchCards as $item)
                                    @php
                                        $match = $item['match'];
                                        $card = $item['card'];
                                    @endphp
                                    <div class="position-relative" wire:key="daily-match-{{ $match->id }}">
                                        {{-- Score Pill Badge overlay --}}
                                        <div class="position-absolute top-0 end-0 m-2 z-1">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill fw-bold">
                                                <i class="fa fa-bolt" aria-hidden="true"></i> {{ $match->score }}% {{ __('Match') }}
                                            </span>
                                        </div>

                                        <x-profile.row :profile="$card">
                                            <x-slot:actions>
                                                <div class="d-flex align-items-center gap-2">
                                                    <a href="{{ route('member.profile.show', $card->code) }}"
                                                       class="btn btn-sm btn-primary"
                                                       wire:navigate>
                                                        <i class="fa fa-user-circle-o" aria-hidden="true"></i> {{ __('View Profile') }}
                                                    </a>
                                                    <button type="button"
                                                            wire:click="dismissMatch('{{ $match->id }}')"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            title="{{ __('Dismiss recommendation') }}">
                                                        <i class="fa fa-times" aria-hidden="true"></i> {{ __('Pass') }}
                                                    </button>
                                                </div>
                                            </x-slot:actions>
                                        </x-profile.row>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            {{-- Empty State --}}
                            <div class="bg-white rounded-3 border p-5 text-center shadow-sm">
                                <div class="mb-3">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light p-3" style="width: 72px; height: 72px;">
                                        <i class="fa fa-bolt fa-2x text-muted" aria-hidden="true"></i>
                                    </span>
                                </div>
                                <h3 class="h4 fw-bold text-dark mb-2">{{ __('No daily matches available today') }}</h3>
                                <p class="text-muted mx-auto mb-4" style="max-width: 500px;">
                                    {{ __('We search for compatible profiles every morning at 05:00 IST. Updating or broadening your partner preferences helps us find more matches for you.') }}
                                </p>
                                <a href="{{ route('member.profile.me') }}#partner-preferences"
                                   class="btn btn-primary"
                                   wire:navigate>
                                    <i class="fa fa-sliders" aria-hidden="true"></i> {{ __('Edit Partner Preferences') }}
                                </a>
                            </div>
                        @endif
                    </div>

                    {{-- Sidebar Column (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <div class="rail-stack d-flex flex-column gap-3">
                            {{-- Info Widget --}}
                            <div class="bg-white rounded-3 border p-3 shadow-sm">
                                <h4 class="h6 fw-bold mb-3 d-flex align-items-center gap-2">
                                    <i class="fa fa-info-circle text-primary" aria-hidden="true"></i>
                                    {{ __('How Daily Matches Work') }}
                                </h4>
                                <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-2">
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('Filtered by your age, community, and education preferences.') }}</span>
                                    </li>
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('Diverse recommendations across districts (max 3 per district).') }}</span>
                                    </li>
                                    <li class="d-flex align-items-start gap-2">
                                        <i class="fa fa-check text-success mt-1" aria-hidden="true"></i>
                                        <span>{{ __('New batch delivered every morning at 05:00 IST.') }}</span>
                                    </li>
                                </ul>
                            </div>

                            {{-- Navigation Shortcut --}}
                            <div class="bg-white rounded-3 border p-3 shadow-sm">
                                <h4 class="h6 fw-bold mb-2">{{ __('More Ways to Discover') }}</h4>
                                <div class="d-grid gap-2">
                                    <a href="{{ route('member.matches') }}" class="btn btn-outline-secondary btn-sm text-start" wire:navigate>
                                        <i class="fa fa-heart-o text-danger me-2" aria-hidden="true"></i> {{ __('My Matches') }}
                                    </a>
                                    <a href="{{ route('member.profiles') }}" class="btn btn-outline-secondary btn-sm text-start" wire:navigate>
                                        <i class="fa fa-users text-primary me-2" aria-hidden="true"></i> {{ __('All Profiles') }}
                                    </a>
                                    <a href="{{ route('member.search') }}" class="btn btn-outline-secondary btn-sm text-start" wire:navigate>
                                        <i class="fa fa-search text-success me-2" aria-hidden="true"></i> {{ __('Advanced Search') }}
                                    </a>
                                </div>
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
