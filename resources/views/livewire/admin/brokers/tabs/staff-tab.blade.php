<div>
    <p class="text-muted-brand">
        {{ __('Seats: :used used of :limit (:open open invitations). Only the owner can invite staff; lowering the limit never deactivates anyone.', ['used' => $seats['used'], 'limit' => $seats['limit'], 'open' => $seats['open_invitations']]) }}
    </p>

    <x-admin.table :headers="[__('Login'), __('Role'), __('Scope'), __('Clients'), __('Last sign-in'), __('Status'), '']" :caption="__('Staff')" :empty="$staff->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-users" :title="__('No logins yet')" :message="__('The owner has not finished applying.')" />
        </x-slot:emptyState>

        @foreach ($staff as $login)
            <tr wire:key="st-{{ $login->id }}">
                <td>
                    <strong>{{ $login->code }}</strong>
                    <div class="small">{{ $login->name ?? '—' }}</div>
                    <div class="small text-muted-brand">{{ $login->user?->phone }}</div>
                </td>
                <td>{{ $login->role->label() }}</td>
                <td>{{ $login->profile_scope->label() }}</td>
                <td>{{ (int) $login->getAttribute('assigned_count') }}</td>
                <td>{{ $login->last_login_at?->timezone($timezone)->format('d M Y, H:i') ?? '—' }}</td>
                <td><x-ui.badge :variant="$login->status === \App\Enums\BureauStaffStatus::Active ? 'success' : 'muted'">{{ $login->status->label() }}</x-ui.badge></td>
                <td class="text-end">
                    @can('brokers.staff.edit')
                        @if ($login->status === \App\Enums\BureauStaffStatus::Active)
                            <div class="d-flex flex-wrap justify-content-end gap-1">
                                <x-ui.button size="sm" variant="ghost" type="button" icon="fa-mobile" wire:click="confirm('staff-reset', '{{ $login->code }}')">{{ __('Reset devices') }}</x-ui.button>
                                @unless ($login->isOwner())
                                    <x-ui.button size="sm" variant="outline" type="button" icon="fa-user-times" wire:click="confirm('staff-deactivate', '{{ $login->code }}')">{{ __('Deactivate') }}</x-ui.button>
                                @endunless
                            </div>
                        @endif
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($invitations->isNotEmpty())
        <h3 class="h6 mt-4">{{ __('Open invitations') }}</h3>
        <ul class="list-unstyled">
            @foreach ($invitations as $invitation)
                <li wire:key="inv-{{ $invitation->id }}">{{ $invitation->name }} · {{ $invitation->role->label() }} · {{ __('expires :at', ['at' => $invitation->expires_at->timezone($timezone)->format('d M, H:i')]) }}</li>
            @endforeach
        </ul>
    @endif

    @can('brokers.staff.edit')
        <x-admin.confirm-modal name="staff-deactivate" :title="__('Deactivate :code', ['code' => $target])" action="deactivate" :confirmLabel="__('Deactivate')">
            <p>{{ __('The login is signed out of every tab now, its clients go back to the unassigned pool and its seat is freed.') }}</p>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="staff-reset" :title="__('Reset devices of :code', ['code' => $target])" action="resetDevices" :confirmLabel="__('Reset devices')">
            <p>{{ __('For a lost or shared phone: every session ends and remembered devices are forgotten, so the next sign-in asks for an OTP.') }}</p>
        </x-admin.confirm-modal>
    @endcan
</div>
