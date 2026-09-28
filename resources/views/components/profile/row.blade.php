{{--
    The `.profiles` result row (template profile-row.php) — all profiles, matches, search,
    interests. The whole row is clickable through .stretched-link on the name (never an <a>
    around the card). URLs use the profile CODE, never the database key.

    profile   App\Data\Profile\ProfileCardData
    actions   optional slot: the button row (Livewire interest/like buttons from P3, or
              Accept/Decline on the interests page). Omitted → no action row.
    shortlist optional slot: the bookmark control on the photo (Favorite, P3.3).
--}}
@props(['profile'])

<div {{ $attributes->class('profiles') }}>
    <div class="profile-img">
        {{-- alt="" — the name is the adjacent <h2>; a filled alt would be read twice. --}}
        <img src="{{ $profile->photoUrl }}" class="img-fluid" alt="" width="600" height="600" loading="lazy" decoding="async">

        {{ $shortlist ?? '' }}

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

            @isset($actions)
                <div class="intst-parent is-flush">
                    <div class="intst-button">
                        {{ $actions }}
                    </div>
                </div>
            @endisset
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
