{{--
    One dashboard slider (M05, template dashboard.php "Daily Recommendations" card): header, the
    .match-slider of profile tiles (started by the `carousel` Alpine component, since this renders
    after the page), and "View All". Visitors on a plan without see_who_viewed_me get a blurred,
    data-free teaser with the count and an upgrade link.
--}}
<div class="dashboard-content dashboard-strip" x-data="carousel">
    <section class="home-content">
        <div class="content-header">
            <div class="content-title">
                <h2>{{ $title }}@if (! $locked && $cards !== []) ({{ count($cards) }})@endif</h2>
                <p>{{ $subtitle }}</p>
            </div>
            @if ($kind === 'daily' && $cards !== [])
                <div class="time-card" x-data="countdown({{ $expiresIn }})">
                    <span>{{ __('Time left to view') }}</span>
                    <h4 x-text="label">{{ gmdate('G\h i\m', $expiresIn) }}</h4>
                </div>
            @endif
        </div>

        @if ($locked)
            <div class="visitor-teaser">
                <div class="visitor-teaser__blur" aria-hidden="true">
                    @for ($i = 0; $i < 3; $i++)<span class="visitor-teaser__tile"></span>@endfor
                </div>
                <div class="visitor-teaser__body">
                    <p class="visitor-teaser__count">{{ trans_choice(':count member viewed your profile in the last 30 days|:count members viewed your profile in the last 30 days', $visitorCount, ['count' => number_format($visitorCount)]) }}</p>
                    <x-ui.button :href="route('plans')" wire:navigate>{{ __('Upgrade to see who') }}</x-ui.button>
                </div>
            </div>
        @elseif ($cards === [])
            <x-ui.empty-state icon="fa-heart-o" :title="$empty" />
        @else
            <div class="swiper match-slider">
                <div class="swiper-wrapper">
                    @foreach ($cards as $card)
                        <x-profile.tile :profile="$card" wire:key="{{ $kind }}-{{ $card->code }}" />
                    @endforeach
                </div>
            </div>
        @endif

        @if ($viewAll && ! $locked)
            <div class="content-btn">
                <a href="{{ $viewAll }}" class="view-btn" wire:navigate>{{ __('View All') }} <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        @endif
    </section>
</div>
