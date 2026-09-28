{{--
    Member layout: every signed-in member page (3/6/3 dashboard pages, wizard, chat…).
    Adds the mobile tab bar and forces noindex (member pages name real people).
    Livewire pages: #[Layout('layouts::member')]. Real-time (Echo) is switched on here in P3.1.

    member  App\Data\Profile\MemberChromeData — the signed-in member (from P1.1)
--}}
@props(['seo' => null, 'title' => null, 'pageNav' => [], 'member' => null])

{{-- Always private, even when a page passes its own $seo: these pages name real people. --}}
@php($seo = ($seo ?? \App\Data\Content\SeoData::private($title ?? \App\Data\Content\SeoData::DEFAULT_TITLE))->asPrivate())
@inject('nav', 'App\Support\Navigation\Navigation')
@php($resolvedPageNav = $nav->pageNav($pageNav))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['seo' => $seo])
</head>
<body class="is-member">
    @include('layouts.partials.preloader')
    @include('layouts.partials.header', ['member' => $member, 'pageNav' => $resolvedPageNav])

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @include('layouts.partials.footer', ['member' => $member])

    @if ($member)
        @include('layouts.partials.mobile-tabbar', ['pageNav' => $resolvedPageNav])
    @endif

    @livewireScripts
</body>
</html>
