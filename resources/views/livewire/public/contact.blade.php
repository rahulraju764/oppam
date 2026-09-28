{{--
    Contact (template contact.php): form (7) beside the details (5), .is-readable so fields don't
    stretch to 1800px. The form becomes <livewire:public.contact-form> in P8.1 (stored, emailed,
    Turnstile). Until then its submit is disabled and says so — a disabled default button also
    blocks Enter-to-submit.
--}}
@php($social = array_filter($site['social']))

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
                    <form class="matrimony-forms" method="post">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="mat-label" for="contact-first-name">{{ __('First name') }}</label>
                                <input type="text" class="form-control mat-text" id="contact-first-name" name="first_name" placeholder="{{ __('First name') }}" autocomplete="given-name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="mat-label" for="contact-last-name">{{ __('Last name') }}</label>
                                <input type="text" class="form-control mat-text" id="contact-last-name" name="last_name" placeholder="{{ __('Last name') }}" autocomplete="family-name">
                            </div>
                            <div class="col-12">
                                <label class="mat-label" for="contact-email">{{ __('Email') }}</label>
                                <input type="email" class="form-control mat-text" id="contact-email" name="email" placeholder="you@example.com" autocomplete="email" required>
                            </div>
                            <div class="col-12">
                                <label class="mat-label" for="contact-subject">{{ __('Subject') }}</label>
                                <select class="form-select mat-text" id="contact-subject" name="subject">
                                    <option>{{ __('General enquiry') }}</option>
                                    <option>{{ __('Registration help') }}</option>
                                    <option>{{ __('Membership & payments') }}</option>
                                    <option>{{ __('Report a profile') }}</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="mat-label" for="contact-message">{{ __('Message') }}</label>
                                <textarea class="form-control mat-text" id="contact-message" name="message" rows="5" placeholder="{{ __('How can we help?') }}" required></textarea>
                            </div>
                        </div>

                        <div class="contact-button">
                            <button type="submit" disabled>
                                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                {{ __('Send Message') }}
                            </button>
                        </div>
                        <p class="form-pending-note">{{ __('The online form opens soon. Until then, please call or email us using the details on this page.') }}</p>
                    </form>
                </div>

                <div class="col-lg-5">
                    <div class="contact-aside">
                        <div class="location">
                            <span class="location-icon"><i class="fa fa-phone" aria-hidden="true"></i></span>
                            <div class="location-body">
                                <h3>{{ __('Contact') }}</h3>
                                <ul class="location-content">
                                    @foreach ($site['emails'] as $email)
                                        <li><a href="mailto:{{ $email }}">{{ $email }}</a></li>
                                    @endforeach
                                    @foreach ($site['phones'] as $dial => $display)
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
                                        @foreach ($site['address'] as $line)
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
                                    <li>{{ $site['hours'] }}</li>
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

    @if ($site['map_embed_url'])
        <section class="map-section">
            <iframe title="{{ __('Oppam Matrimony office location') }}" src="{{ $site['map_embed_url'] }}"
                    class="map-embed" width="600" height="450" allowfullscreen loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"></iframe>
        </section>
    @endif
</div>
