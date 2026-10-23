{{-- A bureau denial (BureauGate: X-Error-Code, e.g. BROKER_KYC_REQUIRED) explains itself with its own fixed text; any other 403 stays generic. --}}
@php($bureauCode = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['X-Error-Code'] ?? null) : null)
@if ($bureauCode !== null)
    @include('errors.layout', ['code' => 403, 'title' => __('Not available for your bureau'), 'lead' => $exception->getMessage()])
@else
    @include('errors.layout', ['code' => 403, 'title' => __('You don’t have access to this page'), 'lead' => __('If you think you should, sign in with the right account or contact support.')])
@endif
