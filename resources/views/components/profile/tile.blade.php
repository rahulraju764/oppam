{{--
    The `.profile-card` slide inside a `.match-slider` (template profile-tile.php). It emits its
    own .swiper-slide — the tile IS the slide everywhere it is used.

    profile   App\Data\Profile\ProfileCardData
--}}
@props(['profile'])

<div class="swiper-slide">
    @if ($profile->url)
        <a href="{{ $profile->url }}" class="profile-link" wire:navigate>
    @else
        <div class="profile-link">
    @endif
        <div class="profile-card">
            {{-- alt="" — the name is in the <h4> directly below. --}}
            <img src="{{ $profile->photoUrl }}" alt="" width="600" height="600" loading="lazy" decoding="async">
            <div class="card-details">
                <h4>{{ $profile->name }} <x-ui.online-dot :code="$profile->code" /></h4>
                <p>{{ collect([$profile->age, $profile->height])->filter()->implode(', ') }}</p>
            </div>
        </div>
    @if ($profile->url)
        </a>
    @else
        </div>
    @endif
    {{-- Outside the link (no button inside an <a>), placed over the tile's corner. --}}
    @if ($profile->liked !== null)
        <livewire:shared.like-button :profile-code="$profile->code" :liked="$profile->liked" :name="$profile->name"
                                     variant="tile" :key="'like-'.$profile->code" />
    @endif
</div>
