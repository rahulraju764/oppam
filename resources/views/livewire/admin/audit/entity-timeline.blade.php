{{--
    A12 per-entity timeline (P9.2a) on the member / bureau / order pages: the audit rows about the
    entity and what it owns, newest first. Read-only.
--}}
<div>
    @if ($viewerLink)
        <div class="d-flex justify-content-end mb-2">
            <a href="{{ route('admin.audit.index', ['subject' => $code]) }}" wire:navigate class="small">
                <i class="fa fa-history" aria-hidden="true"></i> {{ __('Open in the audit log') }}
            </a>
        </div>
    @endif

    <x-admin.table :headers="[__('When (IST)'), __('By'), __('What'), __('Subject'), __('Reason')]" :caption="__('Timeline')" :empty="$entries->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-history" :title="__('Nothing recorded yet')" :message="__('Admin changes and sensitive reads about this record will appear here.')" />
        </x-slot:emptyState>

        @foreach ($entries as $entry)
            <tr wire:key="audit-{{ $entry->id }}">
                <td>{{ $entry->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td>{{ $names[$entry->id] ?? '' }}</td>
                <td><code>{{ $entry->action }}</code></td>
                <td class="small">{{ $entry->subject_type }}{{ $entry->subject_label ? ' · '.$entry->subject_label : '' }}</td>
                <td class="small">{{ $entry->reason ?? '—' }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <div class="mt-3">{{ $entries->links() }}</div>
</div>
