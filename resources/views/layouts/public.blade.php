{{--
    Public layout: marketing, legal and auth pages (template pages that set $is_public, plus the
    static pages reachable from both chromes). Usable as <x-layouts::public> and as the default
    Livewire page layout (config/livewire.php).

    seo      ?App\Data\Content\SeoData  (a Livewire #[Title] alone also works)
    pageNav  runtime overrides for the page-nav bar: ['title' => …, 'prev' => [url, label]|null, …]
    member   ?App\Data\Profile\MemberChromeData — a signed-in member keeps their own chrome on
             shared pages (resolved from the session when not passed; null = logged-out chrome)
--}}
@props(['seo' => null, 'title' => null, 'pageNav' => [], 'member' => null])

@php($seo ??= $title !== null ? new \App\Data\Content\SeoData(title: $title) : new \App\Data\Content\SeoData())
{{-- A signed-in member keeps their own chrome when a page does not pass one (P1.1). --}}
@php($member ??= \App\Data\Profile\MemberChromeData::current())
@inject('nav', 'App\Support\Navigation\Navigation')

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['seo' => $seo])
</head>
<body>
    @include('layouts.partials.preloader')
    @include('layouts.partials.header', ['member' => $member, 'pageNav' => $nav->pageNav($pageNav)])
    <x-impersonation-banner />

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @include('layouts.partials.footer', ['member' => $member])

    @livewireScripts
</body>
</html>
