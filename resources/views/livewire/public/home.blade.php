{{-- Landing page (template index.php). Every section is .container.is-chrome, lined up with the nav. --}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($registerUrl = $nav->url('register'))
@php($loginUrl = $nav->url('login'))

<div>
    <section class="matrimony-slides">
        <h1 class="visually-hidden">{{ __('Oppam Matrimony — trusted Kerala matchmaking') }}</h1>

        {{-- Swiper. Dots and their tooltips are built from the slides (carousels.js), so a new slide
             needs no JS or attribute change. Only the first image is fetchpriority=high (LCP). --}}
        <div class="basement-carousel swiper">
            <div class="swiper-wrapper">
                @foreach ([
                    ['images/banner/banner1.webp', 'Join Today', 'Begin your journey toward a meaningful relationship with trusted and verified matrimonial profiles.'],
                    ['images/banner/banner2.webp', 'Find Matches', 'Discover compatible matches based on your preferences, values, and life aspirations.'],
                    ['images/banner/banner3.webp', 'Connect Securely', 'Communicate safely and confidently with genuine members through secure interactions.'],
                    ['images/banner/banner4.webp', 'Start Forever', 'Take the next step towards a happy marriage and build a lifetime of togetherness.'],
                ] as [$image, $heading, $copy])
                    <div class="basement-content swiper-slide">
                        <div class="basement-images">
                            <img src="{{ asset($image) }}" class="img-fluid" alt="" width="1920" height="700"
                                 @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                        </div>
                        <div class="basement-parent">
                            <div class="basement-details">
                                <h2>{{ __($heading) }}</h2>
                                <p>{{ __($copy) }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button class="swiper-button-prev" type="button" aria-label="{{ __('Previous slide') }}"><span><i class="fa fa-angle-left" aria-hidden="true"></i></span></button>
            <button class="swiper-button-next" type="button" aria-label="{{ __('Next slide') }}"><span><i class="fa fa-angle-right" aria-hidden="true"></i></span></button>
            <div class="swiper-pagination"></div>
        </div>

        {{-- The form rides a layer that shares the page container, so it lines up with the navbar;
             below 992px it flows under the carousel. It becomes <livewire:public.quick-register>
             in P1.1 (M01). Until then the submit is disabled — a disabled default button also
             blocks Enter-to-submit, so nothing (least of all a password) is sent anywhere. --}}
        <div class="hero-form-layer">
            <div class="container is-chrome">
                <form class="register-form" method="post" @if ($registerUrl) action="{{ $registerUrl }}" @endif>
                    @csrf

                    <div class="register-head">
                        <p class="eyebrow">{{ __('Official matrimony service') }}</p>
                        <h2>{{ __('Create your free profile') }}</h2>
                    </div>

                    {{-- Visually-hidden labels: the placeholder says the same word, and the form must fit the banner. --}}
                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-first-name">{{ __('First name') }}</label>
                        <input type="text" class="form-control mat-register" id="reg-first-name" name="first_name" placeholder="{{ __('First name') }}" autocomplete="given-name" required>
                    </div>

                    <fieldset class="form-row gender-head">
                        <legend class="form-label">{{ __('Gender') }}</legend>
                        <div class="gender-options">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="reg-gender-male" value="MALE" required>
                                <label class="form-check-label" for="reg-gender-male">{{ __('Male') }}</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="reg-gender-female" value="FEMALE">
                                <label class="form-check-label" for="reg-gender-female">{{ __('Female') }}</label>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="form-row">
                        <legend class="form-label">{{ __('Date of birth') }}</legend>
                        <div class="row g-2">
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-day" name="dob_day" placeholder="DD" aria-label="{{ __('Date of birth: day') }}" inputmode="numeric" maxlength="2" autocomplete="bday-day" required>
                            </div>
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-month" name="dob_month" placeholder="MM" aria-label="{{ __('Date of birth: month') }}" inputmode="numeric" maxlength="2" autocomplete="bday-month" required>
                            </div>
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-year" name="dob_year" placeholder="YYYY" aria-label="{{ __('Date of birth: year') }}" inputmode="numeric" maxlength="4" autocomplete="bday-year" required>
                            </div>
                        </div>
                    </fieldset>

                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-email">{{ __('Email') }}</label>
                        <input type="email" class="form-control mat-register" id="reg-email" name="email" placeholder="you@example.com" autocomplete="email">
                    </div>

                    <fieldset class="form-row">
                        <legend class="form-label">{{ __('Mobile number') }}</legend>
                        <div class="row g-2">
                            <div class="col-5">
                                <select class="form-select mat-register" id="reg-country-code" name="country_code" aria-label="{{ __('Country code') }}">
                                    <option value="91" selected>India [+91]</option>
                                    <option value="971">UAE [+971]</option>
                                    <option value="966">Saudi Arabia [+966]</option>
                                    <option value="44">UK [+44]</option>
                                    <option value="1">USA [+1]</option>
                                </select>
                            </div>
                            <div class="col-7">
                                <input type="tel" class="form-control mat-register" id="reg-mobile" name="mobile" placeholder="{{ __('Mobile number') }}" aria-label="{{ __('Mobile number') }}" autocomplete="tel-national" required>
                            </div>
                        </div>
                    </fieldset>

                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-password">{{ __('Password') }}</label>
                        <input type="password" class="form-control mat-register" id="reg-password" name="password" placeholder="{{ __('Password') }}" autocomplete="new-password" required>
                    </div>

                    <div class="form-check tick-box">
                        <input class="form-check-input" type="checkbox" id="reg-terms" name="terms" value="1" required>
                        <label class="form-check-label" for="reg-terms">
                            {{ __('I have read and agreed to the') }}
                            <a href="{{ route('terms') }}" wire:navigate>{{ __('Terms of Use') }}</a>
                            {{ __('and') }} <a href="{{ route('privacy') }}" wire:navigate>{{ __('Privacy Policy') }}</a>
                        </label>
                    </div>

                    <div class="regi-button">
                        <button type="submit" @disabled($registerUrl === null)>{{ __('Create an account for free') }}</button>
                    </div>

                    @if ($registerUrl === null)
                        <p class="form-pending-note">{{ __('Online registration opens soon. Meanwhile, call or visit any of our branches.') }}</p>
                    @endif

                    @if ($loginUrl)
                        <p class="account">{{ __('Already have an account?') }} <a href="{{ $loginUrl }}" wire:navigate>{{ __('Login') }}</a></p>
                    @endif
                </form>
            </div>
        </div>
    </section>

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
                        <p class="eyebrow">{{ __('About Oppam') }}</p>
                        <h2>{{ __('Love Can Happen Anywhere, Anytime') }}</h2>
                        <p>{{ __('Oppam brings Malayali families together with verified profiles, private conversations and matchmaking you can trust — from Thrissur to Thiruvananthapuram.') }}</p>
                        <a href="{{ route('about') }}" class="about-btn" wire:navigate>{{ __('Read More') }}</a>

                        @include('livewire.public.partials.about-stats', ['headingTag' => 'h3'])
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="work-section">
        <div class="container is-chrome">
            <div class="section-header">
                <h4 class="theme-color">{{ __('How Does It Work?') }}</h4>
                <h2>{{ __('You’re Just 3 Steps Away From the Right Match') }}</h2>
                @include('livewire.public.partials.title-divider')
            </div>
            <div class="section-wrapper">
                <div class="row justify-content-center g-4">
                    @foreach ([
                        ['images/home/01.png', '01', 'Create A Profile', 'Tell us about yourself, your family and what matters to you. It takes a few minutes.'],
                        ['images/home/02.png', '02', 'Find Matches', 'We surface compatible profiles daily, filtered by the preferences you actually care about.'],
                        ['images/home/03.png', '03', 'Connect & Meet', 'Send an interest, chat privately, and involve your families when you’re both ready.'],
                    ] as [$image, $step, $title, $copy])
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="lab-item">
                                <div class="lab-inner text-center">
                                    <div class="lab-thumb">
                                        <div class="thumb-inner">
                                            <img src="{{ asset($image) }}" alt="" width="144" height="144" loading="lazy" decoding="async">
                                            <div class="step">
                                                <span>{{ __('step') }}</span>
                                                <p>{{ $step }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="lab-content">
                                        <h4>{{ __($title) }}</h4>
                                        <p>{{ __($copy) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="members-section">
        <div class="container is-chrome">
            <div class="row">
                <div class="col-12 text-center">
                    <div class="section-title">
                        <h2>{{ __('Awesome') }} <span>{{ __('Top') }}</span> {{ __('Members') }}</h2>
                        @include('livewire.public.partials.title-divider')
                        <p>{{ __('Every profile is') }} <span class="highlight">{{ __('verified') }}</span> {{ __('before it goes live — real people, real families, genuinely looking for a life partner across Kerala.') }}</p>
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
                        <a href="{{ $registerUrl }}" class="view-btn" wire:navigate>{{ __('View All Members') }} <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="subscription-section">
        <div class="container is-chrome">
            <div class="row">
                <div class="col-12">
                    <div class="subscription-content">
                        <p class="eyebrow">{{ __('Membership Plans') }}</p>
                        <h2>{{ __('Choose the plan that suits you') }}</h2>
                        <p>{{ __('Upgrade to view verified mobile numbers, chat directly with families, and get your profile shown to more matches across Kerala.') }}</p>
                    </div>
                </div>
            </div>

            <x-pricing.cards :plans="$plans" />
        </div>
    </section>

    <section class="testimonial-section">
        <div class="container is-chrome">
            <div class="section-header">
                <h4 class="theme-color">{{ __('Testimonials') }}</h4>
                <h2 class="script-accent">{{ __('Stories of Trust & Happiness') }}</h2>
                @include('livewire.public.partials.title-divider')
            </div>

            <div class="testimonial-carousel swiper">
                <div class="swiper-wrapper">
                    @foreach ($testimonials as $testimonial)
                        <div class="testimonial-card swiper-slide">
                            <div class="testimonial-img">
                                <img src="{{ asset($testimonial['img']) }}" alt="" width="600" height="600" loading="lazy" decoding="async">
                            </div>
                            <div class="testimonial-content">
                                <h4>{{ $testimonial['name'] }}</h4>
                                <span>{{ $testimonial['place'] }}</span>
                                <p>“{{ $testimonial['quote'] }}”</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="swiper-pagination"></div>
            </div>
        </div>
    </section>
</div>
