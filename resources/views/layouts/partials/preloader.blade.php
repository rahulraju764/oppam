{{--
    Load curtain (template header.php). app.js fades it on window.load and removes it; the CSS
    carries an 8 s failsafe; preloader.js removes it from bodies swapped in by wire:navigate.
    NOT .script-accent: the curtain is up exactly while the webfonts download.
--}}
<div class="preloader" id="preloader">
    <div class="preloader-inner">
        <div class="preloader-mark">
            <span class="preloader-ring"></span>
            <span class="preloader-ring preloader-ring-2"></span>
            <img src="{{ asset('images/logo/oppam-logo.webp') }}" class="preloader-logo" alt="" width="600" height="301" fetchpriority="high" decoding="async">
        </div>
        <p class="preloader-title">{{ config('oppam.site.name') }}</p>
        <p class="preloader-tag">{{ __(config('oppam.site.tagline')) }}</p>
        <div class="preloader-bar"><span></span></div>
        <p class="visually-hidden" role="status">{{ __('Loading Oppam Matrimony…') }}</p>
    </div>
</div>
