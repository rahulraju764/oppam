{{--
    One success-story couple (template story-card.php).
    story    App\Data\Content\StoryData
    variant  'grid' (default) — a .story-card tile in the /success-stories grid
             'slide'          — a .parent-stories .swiper-slide for the profile-page rail
    The link always targets the couple's anchor on /success-stories.
--}}
@props(['story', 'variant' => 'grid'])

@if ($variant === 'slide')
    <div class="parent-stories swiper-slide">
        <div class="stories-image">
            <img src="{{ $story->imageUrl }}" class="img-fluid" alt="" width="{{ $story->imageWidth }}" height="{{ $story->imageHeight }}" loading="lazy" decoding="async">
        </div>
        <div class="stories-content">
            <h2>{{ $story->couple }}</h2>
        </div>
        <div class="readstory-btn">
            <a href="{{ $story->url() }}" wire:navigate>{{ __('Read their story') }}</a>
        </div>
    </div>
@else
    <div class="col-lg-4 col-md-6 col-12">
        <div class="story-card">
            <div class="story-card-img">
                {{-- alt="" — the couple's name is the adjacent <h3>. --}}
                <img src="{{ $story->imageUrl }}" class="img-fluid" alt="" width="{{ $story->imageWidth }}" height="{{ $story->imageHeight }}" loading="lazy" decoding="async">
            </div>
            <div class="story-card-body">
                <h3 class="story-card-name">
                    {{-- stretched-link, never a wrapping <a>. Same page → a plain anchor jump. --}}
                    <a href="#story-{{ $story->slug }}" class="stretched-link">{{ $story->couple }}</a>
                </h3>
                <p class="story-card-meta text-muted-brand">{{ $story->place }} &middot; {{ $story->date }}</p>
                <p class="story-card-quote">&ldquo;{{ $story->quote }}&rdquo;</p>
            </div>
        </div>
    </div>
@endif
