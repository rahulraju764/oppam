<div>
    <x-admin.table :headers="[__('When (IST)'), __('By'), __('What'), __('Reason')]" :caption="__('Timeline')" :empty="$entries->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-history" :title="__('Nothing recorded yet')" />
        </x-slot:emptyState>

        @foreach ($entries as $entry)
            <tr wire:key="audit-{{ $entry->id }}">
                <td>{{ $entry->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td>{{ $entry->actor_label ?? $entry->actor_type->label() }}</td>
                <td><code>{{ $entry->action }}</code></td>
                <td class="small">{{ $entry->reason ?? '—' }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <div class="mt-3">{{ $entries->links() }}</div>
</div>
