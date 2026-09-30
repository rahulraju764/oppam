{{--
    Main footer (template footer.php). Same shell for everyone; only "Explore" branches on
    whether a member is signed in ($member). Contact details: SiteContactData (A15 settings).
    Unbuilt routes are skipped.
--}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($site = \App\Data\Content\SiteContactData::current())

<footer class="footer-area footer-bg-two">
    <div class="container is-chrome">
        <div class="row gy-5">

            <div class="col-lg-4 col-md-6">
                <div class="footer-widget footer-brand">
                    <a href="{{ $nav->homeUrl() }}" class="footer-logo" wire:navigate>
                        <img src="{{ asset('images/logo/oppam-logo.webp') }}" alt="{{ config('oppam.site.name') }}" width="600" height="301" loading="lazy" decoding="async">
                    </a>

                    <p class="footer-desc">
                        {{ __('We help individuals and families find meaningful, lifelong relationships through a trusted and secure matrimony platform.') }}
                    </p>

                    @php($social = $site->social)
                    @if ($social !== [])
                        <div class="social_media">
                            @foreach ([
                                'facebook' => ['Facebook', 'fa-facebook'],
                                'twitter' => ['Twitter', 'fa-twitter'],
                                'instagram' => ['Instagram', 'fa-instagram'],
                                'linkedin' => ['LinkedIn', 'fa-linkedin'],
                                'youtube' => ['YouTube', 'fa-youtube-play'],
                            ] as $network => [$networkName, $icon])
                                @isset($social[$network])
                                    <a href="{{ $social[$network] }}" target="_blank" rel="noopener" aria-label="{{ __('Oppam Matrimony on :network', ['network' => $networkName]) }}">
                                        <i class="fa {{ $icon }}" aria-hidden="true"></i>
                                    </a>
                                @endisset
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @php($explore = $nav->links($member
                ? [['member.dashboard', 'Dashboard'], ['member.profile.me', 'My Profile'], ['member.matches.daily', 'Daily Matches'], ['member.profiles', 'All Profiles'], ['member.matches', 'My Matches'], ['member.search', 'Search'], ['member.interests', 'Interests'], ['plans', 'Membership']]
                : [['home', 'Home'], ['register', 'Register Free'], ['login', 'Login'], ['plans', 'Membership'], ['plans', 'How It Works', '#faq']]))
            @if ($explore !== [])
                <div class="col-lg-2 col-md-6 col-6">
                    <div class="footer-widget">
                        <h3>{{ __('Explore') }}</h3>
                        <ul class="fot-list">
                            @foreach ($explore as $link)
                                <li><a href="{{ $link->url }}" wire:navigate>{{ $link->label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @php($support = $nav->links([
                ['success-stories', 'Wedding Success Stories'],
                ['plans', 'FAQ', '#faq'],
                ['contact', 'Contact Us'],
                ['plans', 'Membership Benefits'],
                ['about', 'About Us'],
                ['terms', 'Terms of Use'],
                ['privacy', 'Privacy Policy'],
            ]))
            @if ($support !== [])
                <div class="col-lg-3 col-md-6 col-6">
                    <div class="footer-widget">
                        <h3>{{ __('Help & Support') }}</h3>
                        <ul class="fot-list">
                            @foreach ($support as $link)
                                <li><a href="{{ $link->url }}" wire:navigate>{{ $link->label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="col-lg-3 col-md-6">
                <div class="footer-widget footer-contact">
                    <h3>{{ __('Get In Touch') }}</h3>

                    <div class="office-icon-text">
                        <i class="fa fa-map-marker" aria-hidden="true"></i>
                        <address>
                            @if ($site->mapUrl)
                                <a href="{{ $site->mapUrl }}" target="_blank" rel="noopener">
                            @endif
                            @foreach ($site->addressLines as $line)
                                {{ $line }}@unless ($loop->last)<br>@endunless
                            @endforeach
                            @if ($site->mapUrl)
                                </a>
                            @endif
                        </address>
                    </div>

                    <div class="office-icon-text">
                        <i class="fa fa-phone" aria-hidden="true"></i>
                        <div class="contact-links">
                            @foreach ($site->phones as $dial => $display)
                                <a href="tel:{{ $dial }}">{{ $display }}</a>
                            @endforeach
                        </div>
                    </div>

                    <div class="office-icon-text">
                        <i class="fa fa-envelope" aria-hidden="true"></i>
                        <div class="contact-links">
                            @foreach ($site->emails as $email)
                                <a href="mailto:{{ $email }}">{{ $email }}</a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- No .row here: its negative margins overflowed the strip at 390px (template-notes.md). --}}
        <div class="copyright-area">
            <p class="copyright-text">&copy; {{ now(config('oppam.display_timezone'))->year }} {{ config('oppam.site.name') }}. {{ __('All Rights Reserved.') }}</p>
            <p class="footer-credit">Created by Eyednext</p>
        </div>
    </div>
</footer>
