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
            <form method="POST" action="{{ route('impersonation.end') }}">
                @csrf
                <button type="submit" class="btn btn-light btn-sm">{{ __('End session') }}</button>
            </form>
        </div>
    </div>
@endif
