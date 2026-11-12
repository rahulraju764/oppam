{{--
    A15 integrations (P8.4): each service's configuration (no secrets) and a Test button that makes a
    real, read-only call. Keys are changed in the server's .env / secret manager, never here.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Integrations') }}</h1>
            <p>{{ __('Status of payments, SMS, email, storage, live updates and the bot check. Keys are set on the server and never shown here.') }}</p>
        </div>
    </div>

    <div class="row g-3">
        @foreach ($probes as $key => $probe)
            <div class="col-12 col-md-6 col-xl-4" wire:key="probe-{{ $key }}">
                <div class="ui-card h-100">
                    <h2 class="h6">{{ $probe->label() }}</h2>
                    <dl class="small mb-2">
                        @foreach ($probe->facts() as $label => $value)
                            <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ $label }}</dt><dd class="mb-1 text-end">{{ $value }}</dd></div>
                        @endforeach
                    </dl>

                    <div aria-live="polite">
                        @isset($results[$key])
                            <x-ui.alert :type="$results[$key]['variant']" class="small mb-2">{{ $results[$key]['message'] }}</x-ui.alert>
                        @endisset
                    </div>

                    @if ($canTest)
                        <x-ui.button type="button" variant="secondary" size="sm" wire:click="test('{{ $key }}')" loading="test" dusk="probe-{{ $key }}">
                            {{ $key === 'mail' ? __('Send me a test email') : __('Test connection') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
