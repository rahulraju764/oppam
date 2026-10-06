{{--
    The `.profiles` result row (template profile-row.php) — all profiles, matches, search,
    interests. The whole row is clickable through .stretched-link on the name (never an <a>
    around the card). URLs use the profile CODE, never the database key.

    profile   App\Data\Profile\ProfileCardData
    actions   optional slot: extra controls in the button row (Accept/Decline on the interests
              page, "Not interested" on daily matches …). The Like button follows them whenever
              the card carries the viewer's like state (ProfileCards::forViewers).
    shortlist optional slot replacing the bookmark on the photo; by default the Favorite button,
              whenever the card carries the viewer's bookmark state.
--}}
@props(['profile'])

<div {{ $attributes->class('profiles') }}>
    <div class="profile-img">
        {{-- alt="" — the name is the adjacent <h2>; a filled alt would be read twice. --}}
        <img src="{{ $profile->photoUrl }}" class="img-fluid" alt="" width="600" height="600" loading="lazy" decoding="async">

        @isset($shortlist)
            {{ $shortlist }}
        @elseif ($profile->favorited !== null)
            <livewire:shared.favorite-button :profile-code="$profile->code" :favorited="$profile->favorited" :name="$profile->name"
                                             variant="photo" :key="'fav-'.$profile->code" />
        @endisset

        @if ($profile->isNew)
            <div class="triangle">
                <div class="triagle-content">
                    <span>{{ __('NEWLY') }}<br>{{ __('JOINED') }}</span>
                </div>
            </div>
        @endif
    </div>

    <div class="profile-tittle">
        <h2 class="pf-tt">
            @if ($profile->url)
                <a href="{{ $profile->url }}" class="stretched-link" wire:navigate>{{ $profile->name }}</a>
            @else
                {{ $profile->name }}
            @endif
            <x-ui.online-dot :code="$profile->code" />
        </h2>

        <h3 class="pf-dt-m">
            {{ $profile->code }}
            @if ($profile->meta)
                <span>{{ $profile->meta }}</span>
            @endif
        </h3>

        <div class="profile-list">
            <ul class="list">
                <li class="age">{{ $profile->age }}<span>{{ $profile->height }}</span></li>
                <li class="study">{{ $profile->education }}@if ($profile->occupation),<span> {{ $profile->occupation }}</span>@endif</li>
                <li class="place">{{ $profile->place }}</li>
            </ul>

            @if (isset($actions) || $profile->liked !== null)
                <div class="intst-parent is-flush">
                    <div class="intst-button">
                        {{ $actions ?? '' }}
                        @if ($profile->liked !== null)
                            <livewire:shared.like-button :profile-code="$profile->code" :liked="$profile->liked" :name="$profile->name"
                                                         variant="row" :key="'like-'.$profile->code" />
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($profile->url)
        <div class="icon-parent">
            <div class="profile-icons-m">
                <a href="{{ $profile->url }}" wire:navigate>{{ __('View profile') }}</a>
            </div>
        </div>
    @endif
</div>
