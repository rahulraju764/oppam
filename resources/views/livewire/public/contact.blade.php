{{--
    Contact (template contact.php): form (7) beside the details (5), .is-readable so fields don't
    stretch to 1800px. The form is <livewire:public.contact-form> (P6.2): it opens a support ticket.
    Turnstile and the A08 contact inbox come with P8.1.
--}}
@php($social = $site->social)

<div>
    <section class="contact-section">
        <div class="container is-readable">
            <div class="contact-head">
                <span class="eyebrow">{{ __('Contact Us') }}</span>
                <h1>{{ __('Get in Touch') }}</h1>
                <p>{{ __('Questions about registration, membership or your profile? Our support team will get back to you within one working day.') }}</p>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <livewire:public.contact-form />
                </div>

                <div class="col-lg-5">
                    <div class="contact-aside">
                        <div class="location">
                            <span class="location-icon"><i class="fa fa-phone" aria-hidden="true"></i></span>
                            <div class="location-body">
                                <h3>{{ __('Contact') }}</h3>
                                <ul class="location-content">
                                    @foreach ($site->emails as $email)
                                        <li><a href="mailto:{{ $email }}">{{ $email }}</a></li>
                                    @endforeach
                                    @foreach ($site->phones as $dial => $display)
                                        <li><a href="tel:{{ $dial }}">{{ $display }}</a></li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>

                        <div class="location">
                            <span class="location-icon"><i class="fa fa-map-marker" aria-hidden="true"></i></span>
                            <div class="location-body">
                                <h3>{{ __('Address') }}</h3>
                                <ul class="location-content">
                                    <li>
                                        @foreach ($site->addressLines as $line)
                                            {{ $line }}@unless ($loop->last)<br>@endunless
                                        @endforeach
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="location">
                            <span class="location-icon"><i class="fa fa-clock-o" aria-hidden="true"></i></span>
                            <div class="location-body">
                                <h3>{{ __('Office Hours') }}</h3>
                                <ul class="location-content">
                                    <li>{{ $site->hours }}</li>
                                </ul>
                            </div>
                        </div>

                        @if ($social !== [])
                            <div class="social-media-links">
                                <h2>{{ __('Follow More') }}</h2>
                                <div class="social_media">
                                    @foreach (['facebook' => ['Facebook', 'fa-facebook'], 'twitter' => ['Twitter', 'fa-twitter'], 'instagram' => ['Instagram', 'fa-instagram'], 'linkedin' => ['LinkedIn', 'fa-linkedin'], 'youtube' => ['YouTube', 'fa-youtube-play']] as $network => [$networkName, $icon])
                                        @isset($social[$network])
                                            <a href="{{ $social[$network] }}" target="_blank" rel="noopener" aria-label="{{ __('Oppam Matrimony on :network', ['network' => $networkName]) }}"><i class="fa {{ $icon }}" aria-hidden="true"></i></a>
                                        @endisset
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($site->mapEmbedUrl)
        <section class="map-section">
            <iframe title="{{ __('Oppam Matrimony office location') }}" src="{{ $site->mapEmbedUrl }}"
                    class="map-embed" width="600" height="450" allowfullscreen loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"></iframe>
        </section>
    @endif
</div>
