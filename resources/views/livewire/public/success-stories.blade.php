{{--
    Success Stories (template success-stories.php): a card grid, then each couple's full story
    at #story-<slug> — the cards link to those anchors, so no per-couple page exists.
--}}
<div>
    <section class="stories-section">
        <div class="container is-chrome">
            <div class="section-header">
                <p class="eyebrow">{{ __('Success Stories') }}</p>
                <h1 class="script-accent">{{ __('Stories that started here') }}</h1>
                @include('livewire.public.partials.title-divider')
                <p class="stories-intro">{{ __('Every couple below met on Oppam Matrimony. Their families agreed to let us tell you how it happened.') }}</p>
            </div>

            @if ($stories === [])
                <x-ui.empty-state icon="fa-heart-o" :title="__('No stories yet')" :message="__('The first couples are writing theirs now.')" />
            @else
                <div class="row g-4 story-grid">
                    @foreach ($stories as $story)
                        <x-story.card :story="$story" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($stories !== [])
        <section class="story-longform">
            <div class="container is-readable">
                <h2 class="story-longform-head">{{ __('In their words') }}</h2>
                @foreach ($stories as $story)
                    <article class="story-full" id="story-{{ $story->slug }}">
                        <div class="story-full-head">
                            <div class="story-full-img">
                                <img src="{{ $story->imageUrl }}" class="img-fluid" alt="" width="{{ $story->imageWidth }}" height="{{ $story->imageHeight }}" loading="lazy" decoding="async">
                            </div>
                            <div class="story-full-title">
                                <h3>{{ $story->couple }}</h3>
                                <p class="text-muted-brand">{{ $story->place }} &middot; {{ $story->date }}</p>
                            </div>
                        </div>
                        @foreach ($story->paragraphs as $paragraph)
                            <p class="p-main">{{ $paragraph }}</p>
                        @endforeach
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @include('livewire.public.partials.cta', [
        'title' => __('Yours could be the next one.'),
        'memberText' => __('Keep your profile complete and your daily matches coming.'),
        'memberRoute' => 'member.profiles',
        'memberLabel' => __('Browse Profiles'),
        'guestText' => __('Registration is free, and every profile is verified before it goes live.'),
    ])
</div>
