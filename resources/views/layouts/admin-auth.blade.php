{{-- Admin sign-in pages (login, 2FA challenge/setup, invitation): one centred card. Never indexed. --}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('Sign in') }} | {{ config('oppam.site.name') }} {{ __('Admin') }}</title>
    <link rel="icon" type="image/webp" href="{{ asset('images/logo/oppam-logo.webp') }}">
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @livewireStyles
</head>
<body class="is-admin">
    <main id="main" tabindex="-1" class="admin-auth">
        <div class="admin-auth__card">
            <img src="{{ asset('images/logo/oppam-logo.webp') }}" alt="{{ config('oppam.site.name') }}" class="admin-auth__logo" width="600" height="301">
            {{ $slot }}
        </div>
    </main>

    @livewireScripts
</body>
</html>
