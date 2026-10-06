{{--
    Member layout: every signed-in member page (3/6/3 dashboard pages, wizard, chat…).
    Adds the mobile tab bar and forces noindex (member pages name real people).
    Livewire pages: #[Layout('layouts::member')]. Real-time (Echo, presence, forced logout,
    "Reconnecting…" bar) is switched on here by the realtime-meta tags (P3.1).

    member  App\Data\Profile\MemberChromeData — the signed-in member (from P1.1)
--}}
@props(['seo' => null, 'title' => null, 'pageNav' => [], 'member' => null])

{{-- Always private, even when a page passes its own $seo: these pages name real people. --}}
@php($seo = ($seo ?? \App\Data\Content\SeoData::private($title ?? \App\Data\Content\SeoData::DEFAULT_TITLE))->asPrivate())
{{-- A signed-in member keeps their own chrome when a page does not pass one (P1.1). --}}
@php($member ??= \App\Data\Profile\MemberChromeData::current())
@inject('nav', 'App\Support\Navigation\Navigation')
@php($resolvedPageNav = $nav->pageNav($pageNav))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['seo' => $seo])
    @include('layouts.partials.realtime-meta')
</head>
<body class="is-member">
    @include('layouts.partials.preloader')
    @include('layouts.partials.header', ['member' => $member, 'pageNav' => $resolvedPageNav])
    <x-impersonation-banner />
    {{-- PRD §9.7: shown while the live connection is being re-established (resources/js/realtime.js). --}}
    <div class="realtime-status" role="status" x-data x-show="$store.realtime && $store.realtime.status === 'reconnecting'" x-cloak>
        <i class="fa fa-refresh fa-spin" aria-hidden="true"></i> {{ __('Reconnecting… live updates will resume shortly.') }}
    </div>
    <x-ui.toast-stack />
    @if ($member && \Illuminate\Support\Facades\Route::has('member.likes'))
        <x-engagement.dialogs />
    @endif

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
