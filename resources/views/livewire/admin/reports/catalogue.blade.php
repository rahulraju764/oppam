{{-- A09 report catalogue (P9.1a / P9.1b): the five reports. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Reports') }}</h1>
            <p>{{ __('Numbers are stored once a night, so reports cover up to yesterday. Pick a report, a period, and compare it with the period before.') }}</p>
        </div>
        @can('reports.export')
            <x-ui.button :href="route('admin.reports.exports')" variant="secondary" icon="fa-solid fa-download" wire:navigate>{{ __('My exports') }}</x-ui.button>
        @endcan
    </div>

    <div class="row g-3">
        @foreach ($reports as $report)
            <div class="col-12 col-md-6 col-xl-4" wire:key="report-{{ $report->value }}">
                <div class="ui-card h-100">
                    <h2 class="h5"><i class="{{ $report->icon() }}" aria-hidden="true"></i> {{ $report->label() }}</h2>
                    <p class="text-muted-brand">{{ $report->question() }}</p>
                    <x-ui.button :href="route('admin.reports.show', $report->value)" size="sm" wire:navigate dusk="report-{{ $report->value }}">{{ __('Open report') }}</x-ui.button>
                </div>
            </div>
        @endforeach
    </div>
</div>
