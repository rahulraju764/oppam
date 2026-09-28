{{--
    Broker / bureau portal layout (oppam.in/broker — PRD §11A B.11). Same chrome and tokens as the
    member site; the broker menu and bureau switcher arrive in P7.1. Never indexed.
--}}
@props(['seo' => null, 'title' => null, 'pageNav' => []])

{{-- Always private, even when a page passes its own $seo: these pages name real people. --}}
@php($seo = ($seo ?? \App\Data\Content\SeoData::private($title ?? __('Broker Portal').' | '.config('oppam.site.name')))->asPrivate())
@inject('nav', 'App\Support\Navigation\Navigation')

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['seo' => $seo])
</head>
<body class="is-broker">
    @include('layouts.partials.preloader')
    @include('layouts.partials.header', ['member' => null, 'pageNav' => $nav->pageNav($pageNav)])

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @include('layouts.partials.footer', ['member' => null])

    @livewireScripts
</body>
</html>
