{{-- Landing page (template index.php). Every section is .container.is-chrome, lined up with the nav. --}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($registerUrl = $nav->url('register'))

<div>
    <section @class(['matrimony-slides', 'is-empty' => $banners === []])>
        <h1 class="visually-hidden">{{ __('Oppam Matrimony — trusted Kerala matchmaking') }}</h1>

        {{-- Swiper. Dots and their tooltips are built from the slides (carousels.js), so a new slide
             needs no JS or attribute change. Only the first image is fetchpriority=high (LCP). --}}
        @if ($banners !== [])
        <div class="basement-carousel swiper">
            <div class="swiper-wrapper">
                @foreach ($banners as $banner)
                    <div class="basement-content swiper-slide">
                        <div class="basement-images">
                            <img src="{{ $banner->imageUrl }}" class="img-fluid" alt="{{ $banner->alt }}" width="{{ $banner->imageWidth }}" height="{{ $banner->imageHeight }}"
                                 @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                        </div>
                        <div class="basement-parent">
                            <div class="basement-details">
                                <h2>{{ $banner->heading }}</h2>
                                <p>{{ $banner->copy }}</p>
                                @if ($banner->ctaUrl !== null && $banner->ctaLabel !== null)
                                    <a href="{{ $banner->ctaUrl }}" class="about-btn">{{ $banner->ctaLabel }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button class="swiper-button-prev" type="button" aria-label="{{ __('Previous slide') }}"><span><i class="fa fa-angle-left" aria-hidden="true"></i></span></button>
            <button class="swiper-button-next" type="button" aria-label="{{ __('Next slide') }}"><span><i class="fa fa-angle-right" aria-hidden="true"></i></span></button>
            <div class="swiper-pagination"></div>
        </div>
        @endif

        {{-- The form rides a layer that shares the page container, so it lines up with the navbar;
             below 992px it flows under the carousel. The form itself is Public\QuickRegister (M01). --}}
        <div class="hero-form-layer">
            <div class="container is-chrome">
                @if (app(\App\Queries\Content\PreviewContext::class)->active())
                    <p class="text-muted-brand">{{ __('The registration form is switched off in a preview.') }}</p>
                @else
                    <livewire:public.quick-register />
                @endif
            </div>
        </div>
    </section>

    @isset($blocks['about'])
    <section class="about-section">
        <div class="container is-chrome">
            {{-- g-4, not g-5: a g-5 row's negative margin overflows the 16px gutter at 360px. --}}
            <div class="row align-items-center g-4">
                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-image">
                        <img src="{{ asset('images/home/oppam-proposal-01-800-700.webp') }}" alt="" class="img-fluid" width="800" height="700" loading="lazy" decoding="async">
                        <div class="video-icon">
                            <i class="fa fa-play" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-content">
                        <p class="eyebrow">{{ $blocks['about']->text('eyebrow') }}</p>
                        <h2>{{ $blocks['about']->text('heading') }}</h2>
                        <p>{{ $blocks['about']->text('text') }}</p>
                        <a href="{{ route('about') }}" class="about-btn" wire:navigate>{{ $blocks['about']->text('cta_label') }}</a>

                        @include('livewire.public.partials.about-stats', ['headingTag' => 'h3'])
                    </div>
                </div>
            </div>
        </div>
    </section>
    @endisset

    @isset($blocks['steps'])
    <section class="work-section">
        <div class="container is-chrome">
            <div class="section-header">
                <h4 class="theme-color">{{ $blocks['steps']->text('eyebrow') }}</h4>
                <h2>{{ $blocks['steps']->text('heading') }}</h2>
                @include('livewire.public.partials.title-divider')
            </div>
            <div class="section-wrapper">
                <div class="row justify-content-center g-4">
                    @foreach (['images/home/01.png', 'images/home/02.png', 'images/home/03.png'] as $image)
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="lab-item">
                                <div class="lab-inner text-center">
                                    <div class="lab-thumb">
                                        <div class="thumb-inner">
                                            <img src="{{ asset($image) }}" alt="" width="144" height="144" loading="lazy" decoding="async">
                                            <div class="step">
                                                <span>{{ __('step') }}</span>
                                                <p>{{ sprintf('%02d', $loop->iteration) }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="lab-content">
                                        <h4>{{ $blocks['steps']->text('step'.$loop->iteration.'_title') }}</h4>
                                        <p>{{ $blocks['steps']->text('step'.$loop->iteration.'_text') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @endisset

    @if ($members !== [] && isset($blocks['members']))
    <section class="members-section" data-featured-members>
        <div class="container is-chrome">
            <div class="row">
                <div class="col-12 text-center">
                    <div class="section-title">
                        <h2>{{ $blocks['members']->text('title_start') }} <span>{{ $blocks['members']->text('title_accent') }}</span> {{ $blocks['members']->text('title_end') }}</h2>
                        @include('livewire.public.partials.title-divider')
                        <p>{{ $blocks['members']->text('intro_start') }} <span class="highlight">{{ $blocks['members']->text('intro_highlight') }}</span> {{ $blocks['members']->text('intro_end') }}</p>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                @foreach ($members as $member)
                    <x-profile.member-card :profile="$member" />
                @endforeach
            </div>

            {{-- A logged-out visitor cannot browse members, so this is the sign-up CTA (from P1.1). --}}
            @if ($registerUrl)
                <div class="row">
                    <div class="col-12 text-center">
                        <a href="{{ $registerUrl }}" class="view-btn" wire:navigate>{{ $blocks['members']->text('cta_label') }} <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            @endif
        </div>
    </section>
    @endif

    <section class="subscription-section">
        <div class="container is-chrome">
            @isset($blocks['plans'])
            <div class="row">
                <div class="col-12">
                    <div class="subscription-content">
                        <p class="eyebrow">{{ $blocks['plans']->text('eyebrow') }}</p>
                        <h2>{{ $blocks['plans']->text('heading') }}</h2>
                        <p>{{ $blocks['plans']->text('text') }}</p>
                    </div>
                </div>
            </div>
            @endisset

            <x-pricing.cards :plans="$plans" />
        </div>
    </section>

    @if ($testimonials !== [] && isset($blocks['testimonials']))
    <section class="testimonial-section">
        <div class="container is-chrome">
            <div class="section-header">
                <h4 class="theme-color">{{ $blocks['testimonials']->text('eyebrow') }}</h4>
                <h2 class="script-accent">{{ $blocks['testimonials']->text('heading') }}</h2>
                @include('livewire.public.partials.title-divider')
            </div>

            <div class="testimonial-carousel swiper">
                <div class="swiper-wrapper">
                    @foreach ($testimonials as $testimonial)
                        <div class="testimonial-card swiper-slide">
                            <div class="testimonial-img">
                                <img src="{{ $testimonial->imageUrl }}" alt="" width="{{ $testimonial->imageWidth }}" height="{{ $testimonial->imageHeight }}" loading="lazy" decoding="async">
                            </div>
                            <div class="testimonial-content">
                                <h4>{{ $testimonial->name }}</h4>
                                <span>{{ $testimonial->place }}</span>
                                <p>“{{ $testimonial->quote }}”</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="swiper-pagination"></div>
            </div>
        </div>
    </section>
    @endif
</div>
