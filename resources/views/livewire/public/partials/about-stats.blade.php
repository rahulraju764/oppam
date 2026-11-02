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
                @if (str_starts_with($counter->icon, 'fa-'))
                    <i class="fa {{ $counter->icon }}" aria-hidden="true"></i>
                @else
                    <img src="{{ asset($counter->icon) }}" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
                @endif
            </div>
            <{{ $headingTag }}><span class="counter" data-target="{{ $counter->value }}">0</span></{{ $headingTag }}>
            <p class="text-muted-brand">{{ $counter->label }}</p>
        </div>
    @endforeach
</div>
@endif
