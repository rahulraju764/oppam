<div>
    <x-admin.table :headers="[__('When (IST)'), __('Method'), __('Device'), __('IP address'), __('Browser')]" :caption="__('Sign-ins')" :empty="$events->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-sign-in" :title="__('No sign-ins yet')" />
        </x-slot:emptyState>

        @foreach ($events as $event)
            <tr wire:key="login-{{ $event->id }}">
                <td>{{ $event->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td>{{ $event->method->label() }}</td>
                <td><code>{{ substr($event->device_hash, 0, 8) }}</code></td>
                <td>{{ $event->ip_address ?? '—' }}</td>
                <td class="small">{{ \Illuminate\Support\Str::limit((string) $event->user_agent, 80) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <div class="mt-3">{{ $events->links() }}</div>
</div>
