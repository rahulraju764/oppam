{{--
    Real-time switches for resources/js (PRD §9): rendered only for a signed-in member, so public
    pages never open a WebSocket. See App\Data\Realtime\RealtimeContextData.
--}}
@php($realtime = \App\Data\Realtime\RealtimeContextData::current())
@if ($realtime)
    <meta name="oppam-realtime" content="on">
    <meta name="oppam-user" content="{{ $realtime->userId }}">
    <meta name="oppam-presence" content="{{ $realtime->presence ? 'on' : 'off' }}">
    <meta name="oppam-impersonating" content="{{ $realtime->impersonating ? 'on' : 'off' }}">
    <meta name="oppam-polling-fallback" content="{{ $realtime->pollingFallback ? 'on' : 'off' }}">
@endif
