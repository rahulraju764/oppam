{{--
    The three counters shared by the home "about" band and /about (template: hardcoded in both).
    Demo numbers until the counters come from cached daily stats (M12). $headingTag keeps each
    page's heading outline: h3 under the home band's h2, h2 on /about under its h1.
--}}
<div class="about-stats">
    @foreach ([
        ['images/home/stat-downloads.png', 105, 'K', true, 'Downloaded App'],
        ['images/about/heart.png', 90, '%', false, 'Successful Marriages'],
        ['images/about/computing.png', 50, 'K', true, 'Verified Members'],
    ] as [$icon, $target, $unit, $plus, $label])
        <div class="stat-tile">
            <div class="stat-icon">
                <img src="{{ asset($icon) }}" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
            </div>
            <{{ $headingTag }}><span class="counter" data-target="{{ $target }}">0</span> {{ $unit }}@if ($plus)<sup>+</sup>@endif</{{ $headingTag }}>
            <p class="text-muted-brand">{{ __($label) }}</p>
        </div>
    @endforeach
</div>
