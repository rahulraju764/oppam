{{--
    Admin panel layout (admin.oppam.in — PRD §11.0): collapsible left sidebar (items filtered by
    route existence + @can), top bar with the page title and account menu, content, toast stack.
    Never indexed. Livewire pages: #[Layout('layouts::admin')] with ['title' => …].
--}}
@props(['title' => null])

@inject('adminNav', 'App\Support\Navigation\AdminNavigation')
@php($admin = auth('admin')->user())

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('Admin') }} | {{ config('oppam.site.name') }} {{ __('Admin') }}</title>
    <link rel="icon" type="image/webp" href="{{ asset('images/logo/oppam-logo.webp') }}">
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @livewireStyles
</head>
<body class="is-admin" x-data="{ menu: false }" x-on:keydown.escape.window="menu = false">
    <a class="skip-link" href="#main">{{ __('Skip to main content') }}</a>

    <div class="admin-shell">
        <nav class="admin-sidebar" id="admin-sidebar" x-bind:class="{ 'is-open': menu }" aria-label="{{ __('Admin') }}">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand" wire:navigate>
                <img src="{{ asset('images/logo/oppam-logo.webp') }}" alt="" width="600" height="301">
                <span>{{ __('Oppam Admin') }}</span>
            </a>

            @foreach ($adminNav->sections($admin) as $section => $links)
                <p class="admin-nav-section">{{ __($section) }}</p>
                @foreach ($links as $link)
                    {{-- Plain links (no wire:navigate): Horizon and Pulse are separate apps. --}}
                    <a href="{{ $link->url }}" @class(['admin-nav-link', 'active' => $link->active]) @if ($link->active) aria-current="page" @endif>
                        <i class="fa {{ $link->icon }}" aria-hidden="true"></i>
                        <span>{{ $link->label }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        <div class="admin-main">
            <header class="admin-topbar">
                <button type="button" class="admin-menu-toggle" x-on:click="menu = ! menu" aria-controls="admin-sidebar" x-bind:aria-expanded="menu" aria-label="{{ __('Toggle menu') }}">
                    <i class="fa fa-bars" aria-hidden="true"></i>
                </button>
                <p class="admin-topbar__title">{{ $title ?? __('Admin') }}</p>
                <span class="admin-topbar__spacer"></span>
                @if ($admin)
                    <span class="d-none d-md-inline text-muted-brand">{{ $admin->name }} · {{ $admin->getRoleNames()->map(fn ($role) => \Illuminate\Support\Str::headline($role))->implode(', ') }}</span>
                    <livewire:admin.auth.logout-button />
                @endif
            </header>

            <main id="main" tabindex="-1" class="admin-content">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-admin.toast-stack />

    @livewireScripts
</body>
</html>
