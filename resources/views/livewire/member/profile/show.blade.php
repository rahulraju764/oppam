{{--
    /profile/{code} — member profile (M03, template single-profile.php: .single-detail header card,
    tabbed .dtl-basic-info tables, About, rail, Similar Profiles). Everything here was already
    filtered for this viewer by the component (photos, name, contact access).
--}}
<div>
    <h1 class="visually-hidden">{{ __('Member Profile') }}</h1>

    <section class="single-profile">
        <div class="container">
            @if ($isOwner)
                <x-ui.alert type="info">
                    @if ($preview)
                        {{ __('This is how other members see your profile.') }}
                        <a href="{{ route('member.profile.show', ['profile' => $profile->code]) }}" wire:navigate>{{ __('Back to your view') }}</a>
                    @else
                        {{ __('This is your profile.') }}
                        <a href="{{ route('member.profile.show', ['profile' => $profile->code, 'preview' => 1]) }}" wire:navigate>{{ __('Preview as others see it') }}</a>
                        · <a href="{{ route('member.profile.me') }}" wire:navigate>{{ __('My Profile') }}</a>
                    @endif
                </x-ui.alert>
            @endif

            <div class="row">
                <div class="col-lg-9 col-md-12 col-sm-12 col-12">

                    {{-- Header card --}}
                    <div class="single-detail">
                        <div class="profile-img">
                            @php($primary = $photos[0] ?? null)
                            <img src="{{ $primary?->cardUrl ?? \App\Domain\Media\PhotoUrls::PLACEHOLDER }}" class="img-fluid"
                                 alt="{{ $primary ? __('Photo of :name', ['name' => $name]) : __('No photo yet') }}"
                                 width="600" height="600" fetchpriority="high">
                            @if ($primary?->blurred)
                                <span class="photo-locked"><i class="fa fa-lock" aria-hidden="true"></i> {{ __('Photo visible on request') }}</span>
                            @endif
                        </div>
                        <div class="profile-tittle">
                            <h2 class="pf-tt">{{ $name }}</h2>
                            <h3 class="pf-dt-n">{{ $profile->code }}</h3>
                            <div class="profile-badges">
                                @if ($profile->is_verified)
                                    <x-ui.badge variant="verified" icon="fa-check-circle">{{ __('Verified') }}</x-ui.badge>
                                @endif
                                @if ($profile->is_premium)
                                    <x-ui.badge variant="premium" icon="fa-diamond">{{ __('Premium') }}</x-ui.badge>
                                @endif
                            </div>
                            <div class="profile-list">
                                <ul class="list">
                                    @isset($headline['age'])<li class="age">{{ $headline['age'] }}@isset($headline['height'])<span>{{ $headline['height'] }}</span>@endisset</li>@endisset
                                    @isset($headline['education'])<li class="study">{{ $headline['education'] }}@isset($headline['occupation'])<span>, {{ $headline['occupation'] }}</span>@endisset</li>@endisset
                                    @isset($headline['place'])<li class="place">{{ $headline['place'] }}</li>@endisset
                                </ul>

                                @unless ($isOwner)
                                    {{-- Engagement actions arrive in P3.3 / P3.4 / P4.1 — shown, disabled, with the reason. --}}
                                    <div class="intst-parent">
                                        <div class="intst-button profile-actions">
                                            <button type="button" class="send-int" disabled aria-describedby="actions-soon"><i class="fa fa-heart" aria-hidden="true"></i>{{ __('Like') }}</button>
                                            <button type="button" class="send-int" disabled aria-describedby="actions-soon"><i class="fa fa-star" aria-hidden="true"></i>{{ __('Favorite') }}</button>
                                            <button type="button" class="send-int" disabled aria-describedby="actions-soon"><i class="fa fa-envelope" aria-hidden="true"></i>{{ __('Send Interest') }}</button>
                                            <button type="button" class="send-int" disabled aria-describedby="actions-soon"><i class="fa fa-comments" aria-hidden="true"></i>{{ __('Chat') }}</button>
                                        </div>
                                        <p class="form-text mb-0" id="actions-soon">{{ __('Likes, favorites, interests and chat are opening soon.') }}</p>
                                    </div>
                                @endunless
                            </div>
                        </div>
                    </div>

                    @if (count($photos) > 1)
                        <ul class="profile-gallery" aria-label="{{ __('More photos') }}">
                            @foreach (array_slice($photos, 1) as $photo)
                                <li wire:key="gallery-{{ $photo->uuid }}">
                                    <img src="{{ $photo->thumbUrl }}" alt="{{ $photo->caption ?? __('Photo of :name', ['name' => $name]) }}" width="200" height="200" loading="lazy">
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Tabs --}}
                    <div class="information-table">
                        <ul class="nav nav-tabs tab-link-single" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active profile-tab" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab" aria-controls="personal" aria-selected="true">{{ __('Personal Information') }}</button>
                            </li>
                            @unless ($isOwner)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link profile-tab" id="preference-tab" data-bs-toggle="tab" data-bs-target="#preference" type="button" role="tab" aria-controls="preference" aria-selected="false">{{ __('Partner Preference') }}</button>
                                </li>
                            @endunless
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="personal" role="tabpanel" aria-labelledby="personal-tab" wire:ignore.self>
                                @foreach ($sections as $title => $rows)
                                    <div class="dtl-basic-info">
                                        <div class="basic-info-hd"><h4>{{ $title }}</h4></div>
                                        <div class="dtl-sec">
                                            <table class="table tb-basic-info">
                                                @foreach ($rows as $label => $value)
                                                    <tr><td class="dtl-profile">{{ $label }}</td><td class="dtls">{{ $value }}</td></tr>
                                                @endforeach
                                            </table>
                                        </div>
                                    </div>

                                    @if ($loop->first)
                                        @include('livewire.member.profile.partials.contact')
                                    @endif
                                @endforeach

                                @if ($horoscopeLink)
                                    <p><a href="{{ $horoscopeLink }}" target="_blank" rel="noopener"><i class="fa fa-file-text-o" aria-hidden="true"></i> {{ __('View horoscope') }}</a></p>
                                @endif
                            </div>

                            @unless ($isOwner)
                                <div class="tab-pane fade" id="preference" role="tabpanel" aria-labelledby="preference-tab" wire:ignore.self>
                                    @include('livewire.member.profile.partials.preferences')
                                </div>
                            @endunless
                        </div>
                    </div>

                    {{-- About --}}
                    @if ($profile->about)
                        <div class="about-myself">
                            <div class="about-head">
                                <h2>{{ __('About :name', ['name' => $name]) }}</h2>
                                <p>{{ $profile->about }}</p>
                            </div>
                        </div>
                    @endif

                    @if ($neighbours['previous'] || $neighbours['next'])
                        <nav class="profile-neighbours" aria-label="{{ __('Browse profiles') }}">
                            @if ($neighbours['previous'])
                                <a href="{{ route('member.profile.show', ['profile' => $neighbours['previous']]) }}" wire:navigate rel="prev"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Previous profile') }}</a>
                            @endif
                            @if ($neighbours['next'])
                                <a href="{{ route('member.profile.show', ['profile' => $neighbours['next']]) }}" wire:navigate rel="next">{{ __('Next profile') }} <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                            @endif
                        </nav>
                    @endif
                </div>

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>

    @if ($similar !== [])
        <section class="similar-profile-other">
            <div class="container">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="similar-head"><h2>{{ __('Similar Profiles') }}</h2></div>
                    </div>
                    @foreach ($similar as $card)
                        <x-profile.member-card :profile="$card" wire:key="similar-{{ $card->code }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
