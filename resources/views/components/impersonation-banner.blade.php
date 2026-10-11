{{--
    <x-impersonation-banner> — shown on every member page while an admin is viewing the account
    for support (A01 "banner in member UI"). Says whose account, until when, and ends it with one
    button. Renders nothing otherwise. Restricted actions are refused in their Actions, not here.
--}}
@php($impersonation = app(\App\Services\Admin\Impersonation::class)->current())

@if ($impersonation)
    <div class="impersonation-banner" role="status">
        <div class="container impersonation-banner__inner">
            <p class="mb-0">
                <i class="fa fa-user-secret" aria-hidden="true"></i>
                {{ __('Support view: you are signed in as this member (:admin). Ends at :time IST. Password, email, payments, contact views and anything that reaches other members are disabled.', [
                    'admin' => $impersonation->admin_label,
                    'time' => $impersonation->expires_at?->timezone(config('oppam.display_timezone'))->format('g:i a'),
                ]) }}
            </p>
            {{-- This tab is ending the session itself: mark it, so the live ".session.revoked" that
                 follows doesn't reload it away from the "Support session ended" page (realtime.js).
                 The mark lapses after 10 s, so a submit that never completes can't leave the tab
                 deaf to a later end of the session. --}}
            <form method="POST" action="{{ route('impersonation.end') }}" x-data
                  x-on:submit="document.documentElement.dataset.endingSupportSession = 'on'; setTimeout(() => delete document.documentElement.dataset.endingSupportSession, 10000)">
                @csrf
                <button type="submit" class="btn btn-light btn-sm">{{ __('End session') }}</button>
            </form>
        </div>
    </div>
@endif
