<div>
    <main id="main" tabindex="-1">
        <h1 class="visually-hidden">{{ __('Dashboard') }}</h1>

        <section class="dashboard-section">
            <div class="container">
                <div class="row dashboard-row">

                    {{-- LEFT COLUMN: Member Sidebar (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <div class="dashboard-sidebar">
                            <div class="sidebar-profile">
                                <a href="{{ route('member.profile.me') }}" wire:navigate>
                                    <img src="{{ $ownPhotoUrl }}" alt="" width="600" height="600" loading="lazy" decoding="async">
                                </a>

                                <h3>{{ $profile->fullName() }}</h3>
                                <p>{{ $profile->code }}</p>
                                <span>{{ $plan ? $plan->code->label() : __('Free Member') }}</span>

                                <div class="px-3 mt-3 w-100">
                                    <div class="d-flex justify-content-between small text-muted mb-1">
                                        <span>{{ __('Profile Completeness') }}</span>
                                        <span class="fw-semibold">{{ $profile->completeness }}%</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $profile->completeness }}%;" aria-valuenow="{{ $profile->completeness }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>

                            @if (! $plan)
                                <div class="membership-box">
                                    <p>{{ __('Upgrade membership to connect & chat with matches') }}</p>
                                    <a href="{{ route('plans') }}" class="upgrade-btn" wire:navigate>
                                        {{ __('Upgrade Now') }}
                                    </a>
                                </div>
                            @endif

                            <div class="sidebar-menu">
                                <a href="{{ route('member.profile.me') }}" wire:navigate>
                                    <i class="fa fa-user-o" aria-hidden="true"></i>
                                    {{ __('My Profile') }}
                                </a>

                                <a href="{{ route('member.profile.me') }}#partner-preferences" wire:navigate>
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    {{ __('Partner Preferences') }}
                                </a>

                                <a href="{{ route('member.my-matches') }}" wire:navigate>
                                    <i class="fa fa-heart-o" aria-hidden="true"></i>
                                    {{ __('My Matches') }}
                                </a>

                                <a href="{{ route('member.all-profiles') }}" wire:navigate>
                                    <i class="fa fa-users" aria-hidden="true"></i>
                                    {{ __('All Profiles') }}
                                </a>

                                <a href="{{ route('member.search') }}" wire:navigate>
                                    <i class="fa fa-search" aria-hidden="true"></i>
                                    {{ __('Advanced Search') }}
                                </a>

                                @if (\Illuminate\Support\Facades\Route::has('member.visitors'))
                                    <a href="{{ route('member.visitors') }}" wire:navigate>
                                        <i class="fa fa-eye" aria-hidden="true"></i>
                                        {{ __('Who Viewed Me') }} ({{ $visitorsCount }})
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- CENTRE COLUMN: Content Area (6 cols) --}}
                    <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                        {{-- Daily Recommendations Slider --}}
                        <div class="dashboard-content mb-4">
                            <section class="home-content">
                                <div class="content-header">
                                    <div class="content-title">
                                        <h2>{{ __('Daily Recommendations') }} ({{ count($dailyCards) }})</h2>
                                        <p>{{ __('Recommended matches for today') }}</p>
                                    </div>

                                    <div class="time-card" x-data="{
                                        seconds: {{ $secondsUntilMidnight }},
                                        format() {
                                            const h = Math.floor(this.seconds / 3600);
                                            const m = Math.floor((this.seconds % 3600) / 60);
                                            const s = this.seconds % 60;
                                            return `${h}h:${m < 10 ? '0' : ''}${m}m:${s < 10 ? '0' : ''}${s}s`;
                                        }
                                    }" x-init="setInterval(() => { if (seconds > 0) seconds-- }, 1000)">
                                        <span>{{ __('Time left to view') }}</span>
                                        <h4 x-text="format()">--:--:--</h4>
                                    </div>
                                </div>

                                @if (! empty($dailyCards))
                                    <div class="swiper match-slider" x-data x-init="new Swiper($el, {
                                        slidesPerView: 1.2,
                                        spaceBetween: 16,
                                        breakpoints: {
                                            480: { slidesPerView: 2 },
                                            768: { slidesPerView: 3 }
                                        }
                                    })">
                                        <div class="swiper-wrapper">
                                            @foreach ($dailyCards as $card)
                                                <x-profile.tile :profile="$card" wire:key="daily-card-{{ $card->code }}" />
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="content-btn mt-3">
                                        <a href="{{ route('member.all-profiles') }}" class="view-btn" wire:navigate>
                                            {{ __('View All Recommendations') }}
                                        </a>
                                    </div>
                                @else
                                    <div class="py-4 text-center text-muted">
                                        <p>{{ __('No new daily recommendations right now.') }}</p>
                                    </div>
                                @endif
                            </section>
                        </div>

                        {{-- New Matches Section --}}
                        <div class="dashboard-content mb-4">
                            <section class="home-content">
                                <div class="content-header">
                                    <div class="content-title">
                                        <h2>{{ __('New Matches') }}</h2>
                                        <p>{{ __('Members who recently joined Oppam') }}</p>
                                    </div>
                                </div>

                                @if (! empty($newCards))
                                    <div class="swiper match-slider" x-data x-init="new Swiper($el, {
                                        slidesPerView: 1.2,
                                        spaceBetween: 16,
                                        breakpoints: {
                                            480: { slidesPerView: 2 },
                                            768: { slidesPerView: 3 }
                                        }
                                    })">
                                        <div class="swiper-wrapper">
                                            @foreach ($newCards as $card)
                                                <x-profile.tile :profile="$card" wire:key="new-card-{{ $card->code }}" />
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="content-btn mt-3">
                                        <a href="{{ route('member.my-matches', ['tab' => 'new']) }}" class="view-btn" wire:navigate>
                                            {{ __('View All New Matches') }}
                                        </a>
                                    </div>
                                @else
                                    <div class="py-3 text-center text-muted">
                                        <p>{{ __('No new matches in the last 7 days.') }}</p>
                                    </div>
                                @endif
                            </section>
                        </div>

                        {{-- Premium Members Strip --}}
                        @if (! empty($premiumCards))
                            <div class="dashboard-content mb-4">
                                <section class="home-content">
                                    <div class="content-header">
                                        <div class="content-title">
                                            <h2>{{ __('Premium Members') }}</h2>
                                            <p>{{ __('Highlighted verified profiles') }}</p>
                                        </div>
                                    </div>

                                    <div class="swiper match-slider" x-data x-init="new Swiper($el, {
                                        slidesPerView: 1.2,
                                        spaceBetween: 16,
                                        breakpoints: {
                                            480: { slidesPerView: 2 },
                                            768: { slidesPerView: 3 }
                                        }
                                    })">
                                        <div class="swiper-wrapper">
                                            @foreach ($premiumCards as $card)
                                                <x-profile.tile :profile="$card" wire:key="premium-card-{{ $card->code }}" />
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="content-btn mt-3">
                                        <a href="{{ route('member.my-matches', ['tab' => 'premium']) }}" class="view-btn" wire:navigate>
                                            {{ __('View All Premium Profiles') }}
                                        </a>
                                    </div>
                                </section>
                            </div>
                        @endif

                    </div>

                    {{-- RIGHT COLUMN: Ads & Promos (3 cols) --}}
                    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                        <x-ads.rail />
                    </div>

                </div>
            </div>
        </section>
    </main>
</div>
