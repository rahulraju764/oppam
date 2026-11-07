{{--
    Right-hand ad rail on 3/6/3 member pages (template ads.php). Sticky on desktop; put it LAST
    in the row so phones get content first. Units carry data-rail="weave" so mobile-rail.js can
    weave them between result cards below 992px.
    Creatives come from A08 ad slots (AdRotation, P8.1b): audience, running window, weighted
    rotation. With no live promo a free member sees the membership card; paid members see none.
    The "Wedding Services" unit is rendered only when its entries have real destinations.

    services  list<array{label: string, icon: string, url: string}>  (default: none)
--}}
@props(['services' => []])
@inject('nav', 'App\Support\Navigation\Navigation')
@inject('rotation', 'App\Queries\Content\AdRotation')
@php
    $plansUrl = $nav->url('plans');
    $rail = $rotation->forViewer(auth('web')->user());
@endphp

<aside class="ad-rail" aria-label="{{ __('Sponsored') }}">
    @if ($rail->promo)
        <div class="ad-unit ad-promo" data-rail="weave">
            <span class="ad-promo-icon"><i class="fa fa-diamond" aria-hidden="true"></i></span>
            <h3>{{ $rail->promo->heading }}</h3>
            @if ($rail->promo->copy)
                <p>{{ $rail->promo->copy }}</p>
            @endif
            <a href="{{ $rail->promo->ctaUrl }}" class="ad-promo-btn"
               @if ($rail->promo->external) target="_blank" rel="noopener sponsored" @else wire:navigate @endif>{{ $rail->promo->ctaLabel }}</a>
        </div>
    @elseif ($rail->showUpgrade && $plansUrl)
        <div class="ad-unit ad-promo" data-rail="weave">
            <span class="ad-promo-icon"><i class="fa fa-diamond" aria-hidden="true"></i></span>
            <h3>{{ __('Go Premium') }}</h3>
            <p>{{ __('Call and chat with your matches, see who viewed you, and get 3x more responses.') }}</p>
            <a href="{{ $plansUrl }}" class="ad-promo-btn" wire:navigate>{{ __('Upgrade Now') }}</a>
        </div>
    @endif

    @if ($rail->banner && $rail->banner->imageUrl)
        <a href="{{ $rail->banner->ctaUrl }}" class="ad-unit ad-banner" data-rail="weave"
           @if ($rail->banner->external) target="_blank" rel="noopener sponsored" @else wire:navigate @endif>
            <span class="ad-label">{{ __('Sponsored') }}</span>
            <img src="{{ $rail->banner->imageUrl }}" class="img-fluid" alt="{{ $rail->banner->alt ?: $rail->banner->heading }}"
                 width="{{ $rail->banner->imageWidth }}" height="{{ $rail->banner->imageHeight }}" loading="lazy" decoding="async">
        </a>
    @endif

    @if ($rail->carousel !== [])
        <div class="ad-unit ad-carousel" data-rail="weave">
            <span class="ad-label">{{ __('Sponsored') }}</span>
            <div class="ad-oppam swiper">
                <div class="swiper-wrapper">
                    @foreach ($rail->carousel as $slide)
                        <div class="oppam-ad swiper-slide">
                            <a href="{{ $slide->ctaUrl }}" class="oppam-img d-block"
                               @if ($slide->external) target="_blank" rel="noopener sponsored" @else wire:navigate @endif>
                                <img src="{{ $slide->imageUrl }}" alt="{{ $slide->alt ?: $slide->heading }}"
                                     width="{{ $slide->imageWidth }}" height="{{ $slide->imageHeight }}" loading="lazy" decoding="async">
                            </a>
                        </div>
                    @endforeach
                </div>
                <div class="swiper-pagination"></div>
            </div>
        </div>
    @endif

    @if ($services !== [])
        <div class="ad-unit ad-services" data-rail="weave">
            <span class="ad-label">{{ __('Sponsored') }}</span>
            <h3>{{ __('Wedding Services') }}</h3>
            <ul class="ad-service-list">
                @foreach ($services as $service)
                    <li>
                        <a href="{{ $service['url'] }}" target="_blank" rel="noopener sponsored">
                            <i class="fa {{ $service['icon'] }}" aria-hidden="true"></i>
                            {{ $service['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</aside>
