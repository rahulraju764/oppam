{{-- A15 maintenance message (P8.4): the admin's text when set, else the built-in text (also when settings can't be read). --}}
@php($maintenance = app(\App\Services\Settings\SiteIdentity::class)->maintenanceMessage())
@include('errors.layout', ['code' => 503, 'title' => __('We’ll be right back'), 'lead' => $maintenance !== '' ? $maintenance : __(':site is down for scheduled maintenance. Please check back shortly.', ['site' => config('oppam.site.name')]), 'showLinks' => false])
