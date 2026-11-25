{{-- A09 "my exports" (P9.1a): the admin's own background report exports. --}}
<div @if ($exports->contains(fn ($e) => $e->status === \App\Enums\AdminExportStatus::Queued)) wire:poll.15s @endif>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.reports.index') }}" wire:navigate class="small">{{ __('← All reports') }}</a></p>
            <h1>{{ __('My report exports') }}</h1>
            <p>{{ __('Large exports are prepared in the background. We email you when one is ready; it can be downloaded here for 24 hours.') }}</p>
        </div>
    </div>

    <x-admin.table :headers="[__('Export'), __('Report'), __('Period'), __('Rows'), __('Status'), __('Requested (IST)'), '']" :caption="__('My report exports')" :empty="$exports->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-solid fa-download" :title="__('No exports yet')" :message="__('Exports larger than a quick download appear here.')" />
        </x-slot:emptyState>
        @foreach ($exports as $export)
            <tr wire:key="export-{{ $export->code }}">
                <td><code>{{ $export->code }}</code> <span class="small text-muted-brand">{{ $export->format->label() }}</span></td>
                <td>{{ \App\Enums\ReportKey::tryFrom($export->report)?->label() ?? $export->report }}</td>
                <td class="small">{{ $export->params['from'] }} – {{ $export->params['to'] }}</td>
                <td>{{ $export->rows === null ? '—' : number_format($export->rows) }}</td>
                <td><x-ui.badge :variant="$export->status->badgeVariant()">{{ $export->status->label() }}</x-ui.badge></td>
                <td class="small">{{ $export->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td class="text-end">
                    @if ($export->status === \App\Enums\AdminExportStatus::Ready)
                        <a href="{{ route('admin.reports.exports.download', $export->code) }}" class="small" dusk="download-{{ $export->code }}">{{ __('Download') }}</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>
</div>
