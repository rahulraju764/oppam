<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Sessions') }}</h1>
            <p>{{ __('Signed-in admin sessions. Sessions end after :idle minutes idle or :hours hours in total.', [
                'idle' => config('oppam.admin.idle_timeout_minutes'),
                'hours' => intdiv(config('oppam.admin.absolute_timeout_minutes'), 60),
            ]) }}</p>
        </div>
    </div>

    <x-admin.table :headers="[__('Admin'), __('Device'), __('IP address'), __('Started (IST)'), __('Last active (IST)'), '']" :caption="__('Live sessions')" :empty="$this->sessions->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-desktop" :title="__('No live sessions')" />
        </x-slot:emptyState>

        @foreach ($this->sessions as $session)
            <tr wire:key="session-{{ $session->id }}">
                <td>{{ $session->admin?->name }} <span class="text-muted-brand">{{ $session->admin?->email }}</span></td>
                <td>{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 60) }}</td>
                <td>{{ $session->ip_address }}</td>
                <td>{{ $session->created_at->timezone(config('oppam.display_timezone'))->format('d M, g:i a') }}</td>
                <td>{{ $session->last_seen_at->timezone(config('oppam.display_timezone'))->format('d M, g:i a') }}</td>
                <td class="text-end">
                    @if ($session->id === $this->currentSessionId)
                        <x-ui.badge variant="success">{{ __('This session') }}</x-ui.badge>
                    @endif
                    <x-ui.button size="sm" variant="outline" type="button" wire:click="revoke('{{ $session->id }}')" loading="revoke">{{ __('End session') }}</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-admin.table>
</div>
