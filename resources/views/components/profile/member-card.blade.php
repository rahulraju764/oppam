{{--
    The 6-up `.member-card` grid tile (template member-card.php) — home "Top Members",
    profile "Similar Profiles". Emits its own Bootstrap column. The link is a full-tile overlay
    (.member-link) with a visually-hidden label; it is omitted when the viewer may not open
    the profile (e.g. a logged-out visitor on the home page).

    profile   App\Data\Profile\ProfileCardData
--}}
@props(['profile'])

<div class="col-lg-2 col-md-4 col-sm-6 col-6">
    <div class="member-card">
        <div class="member-image">
            {{-- alt="" — the name is the <h5> in the overlay below. --}}
            <img src="{{ $profile->photoUrl }}" alt="" class="img-fluid" width="600" height="600" loading="lazy" decoding="async">
        </div>
        <div class="member-info">
            <div class="overlay"></div>
            <h5>{{ $profile->name }}</h5>
            <p>{{ $profile->place }}</p>
        </div>
        @if ($profile->url)
            <a href="{{ $profile->url }}" class="member-link" wire:navigate>
                <span class="visually-hidden">{{ __('View :name’s profile', ['name' => $profile->name]) }}</span>
            </a>
        @endif
    </div>
</div>
