{{--
    The whole <head> for every member-facing page (template head.php + links.php).
    $seo: App\Data\Content\SeoData. Robots is forced to noindex until the A15 setting
    seo.indexable is on (the template's SITE_LIVE; defaults to APP_INDEXABLE). canonical / og:url
    are absolute and host-pinned (the public routes only answer on config('oppam.app_domain')).
--}}
@php($canonical = url()->current())
@php($indexable = \App\Support\Facades\Settings::bool(\App\Enums\SettingKey::SeoIndexable))
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<meta name="keywords" content="{{ $seo->keywords }}">
<link rel="canonical" href="{{ $canonical }}">

<meta name="robots" content="{{ $seo->robots($indexable) }}">
@unless ($indexable)
    <meta name="googlebot" content="noindex, nofollow">
@endunless

<meta property="og:locale" content="en_IN">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('oppam.site.name') }}">
<meta property="og:title" content="{{ $seo->ogTitle() }}">
<meta property="og:description" content="{{ $seo->ogDescription() }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ asset($seo->ogImage) }}">
<meta property="og:image:width" content="{{ $seo->ogImageWidth }}">
<meta property="og:image:height" content="{{ $seo->ogImageHeight }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->ogTitle() }}">
<meta name="twitter:description" content="{{ $seo->ogDescription() }}">
<meta name="twitter:image" content="{{ asset($seo->ogImage) }}">

<meta name="theme-color" content="#e02349">
<link rel="icon" type="image/webp" sizes="32x32" href="{{ asset('images/logo/oppam-logo.webp') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
