{{--
    The real figures shared by the home "about" band and /about (M12, cached daily): a figure still
    at zero isn't shown. $headingTag keeps each page's heading outline: h3 under the home band's h2,
    h2 on /about under its h1.
--}}
@if ($counters !== [])
<div class="about-stats">
    @foreach ($counters as $counter)
        <div class="stat-tile">
            <div class="stat-icon">
                <img src="{{ asset($counter->icon) }}" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
            </div>
            <{{ $headingTag }}><span class="counter" data-target="{{ $counter->value }}">0</span></{{ $headingTag }}>
            <p class="text-muted-brand">{{ $counter->label }}</p>
        </div>
    @endforeach
</div>
@endif
