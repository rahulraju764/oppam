{{--
    Right-hand ad rail on 3/6/3 member pages (template ads.php). Sticky on desktop; put it LAST
    in the row so phones get content first. Units carry data-rail="weave" so mobile-rail.js can
    weave them between result cards below 992px.
    The creative is the template's demo set until ads are managed in A08 (P8.1). The "Wedding
    Services" unit is rendered only when its entries have real destinations (template: href="#").

    services  list<array{label: string, icon: string, url: string}>  (default: none)
--}}
@props(['services' => []])
@inject('nav', 'App\Support\Navigation\Navigation')
@php($plansUrl = $nav->url('plans'))

<aside class="ad-rail" aria-label="{{ __('Sponsored') }}">
    @if ($plansUrl)
        <div class="ad-unit ad-promo" data-rail="weave">
            <span class="ad-promo-icon"><i class="fa fa-diamond" aria-hidden="true"></i></span>
            <h3>{{ __('Go Premium') }}</h3>
            <p>{{ __('Call and chat with your matches, see who viewed you, and get 3x more responses.') }}</p>
            <a href="{{ $plansUrl }}" class="ad-promo-btn" wire:navigate>{{ __('Upgrade Now') }}</a>
        </div>

        <a href="{{ $plansUrl }}" class="ad-unit ad-banner" data-rail="weave" wire:navigate>
            <span class="ad-label">{{ __('Sponsored') }}</span>
            <img src="{{ asset('images/home/ad1.jpg') }}" class="img-fluid" alt="{{ __('Limited time membership offer') }}" width="800" height="800" loading="lazy" decoding="async">
        </a>
    @endif

    <div class="ad-unit ad-carousel" data-rail="weave">
        <span class="ad-label">{{ __('Sponsored') }}</span>
        <div class="ad-oppam swiper">
            <div class="swiper-wrapper">
                @foreach (['images/search/ed-ad1.jpeg', 'images/search/ed-ad2.jpeg'] as $creative)
                    <div class="oppam-ad swiper-slide">
                        <div class="oppam-img">
                            <img src="{{ asset($creative) }}" alt="" width="810" height="1013" loading="lazy" decoding="async">
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="swiper-pagination"></div>
        </div>
    </div>

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
