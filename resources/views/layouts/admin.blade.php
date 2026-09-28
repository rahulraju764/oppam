{{--
    Admin panel layout (admin.oppam.in — PRD §11.0). Plain skeleton; P0.5 adds the sidebar
    (@can-filtered), top bar, toast stack and the admin skin. Never indexed.
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('Admin') }} | {{ config('oppam.site.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="is-admin">
    <a class="skip-link" href="#main">{{ __('Skip to main content') }}</a>

    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
