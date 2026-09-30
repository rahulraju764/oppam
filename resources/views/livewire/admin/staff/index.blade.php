<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Admin users') }}</h1>
            <p>{{ __('Staff accounts, their roles and access. Every change is written to the audit log.') }}</p>
        </div>
        @can('staff.invite')
            <x-ui.button type="button" icon="fa-user-plus" x-on:click="$dispatch('open-modal', { name: 'invite-admin' })">{{ __('Invite staff') }}</x-ui.button>
        @endcan
    </div>

    <div class="mb-3 admin-search">
        <x-ui.input :label="__('Search by name or email')" name="search" type="search" wire:model.live.debounce.400ms="search" />
    </div>

    <x-admin.table :headers="[__('Name'), __('Email'), __('Role'), __('Status'), __('Last sign-in'), '']" :caption="__('Admin users')" :empty="$this->admins->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-user-secret" :title="__('No admins match')" :message="__('Try a different name or email.')" />
        </x-slot:emptyState>

        @foreach ($this->admins as $admin)
            <tr wire:key="admin-{{ $admin->id }}">
                <td>{{ $admin->name }}</td>
                <td>{{ $admin->email }}</td>
                <td>
                    @can('staff.edit')
                        @if (! $admin->is(auth('admin')->user()))
                            <div class="d-flex gap-2 align-items-center">
                                <label class="visually-hidden" for="role-{{ $admin->id }}">{{ __('Role for :name', ['name' => $admin->name]) }}</label>
                                <select id="role-{{ $admin->id }}" name="role-{{ $admin->id }}" class="form-select form-select-sm" wire:model="roleChoice.{{ $admin->id }}">
                                    <option value="">{{ \Illuminate\Support\Str::headline($admin->roles->first()?->name ?? '—') }}</option>
                                    @foreach ($this->roles as $role)
                                        <option value="{{ $role->name }}">{{ \Illuminate\Support\Str::headline($role->name) }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button size="sm" variant="outline" type="button" wire:click="changeRole('{{ $admin->id }}')" loading="changeRole">{{ __('Save') }}</x-ui.button>
                            </div>
                        @else
                            {{ \Illuminate\Support\Str::headline($admin->roles->first()?->name ?? '—') }}
                        @endif
                    @else
                        {{ \Illuminate\Support\Str::headline($admin->roles->first()?->name ?? '—') }}
                    @endcan
                </td>
                <td>
                    @if ($admin->isLocked())
                        <x-ui.badge variant="warning" icon="fa-lock">{{ __('Locked') }}</x-ui.badge>
                    @elseif ($admin->isActive())
                        <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="muted">{{ __('Suspended') }}</x-ui.badge>
                    @endif
                    @unless ($admin->hasConfirmedTwoFactor())
                        <x-ui.badge variant="muted">{{ __('2FA not set up') }}</x-ui.badge>
                    @endunless
                </td>
                <td>{{ $admin->last_login_at?->timezone(config('oppam.display_timezone'))->format('d M Y, g:i a') ?? __('Never') }}</td>
                <td class="text-end">
                    @can('staff.suspend')
                        @unless ($admin->is(auth('admin')->user()))
                            <x-ui.button size="sm" :variant="$admin->isActive() && ! $admin->isLocked() ? 'danger' : 'secondary'" type="button"
                                         wire:click="confirmStatusChange('{{ $admin->id }}')">
                                {{ $admin->isActive() && ! $admin->isLocked() ? __('Suspend') : __('Reactivate') }}
                            </x-ui.button>
                        @endunless
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    <div class="mt-3">{{ $this->admins->links() }}</div>

    @if ($this->pendingInvitations->isNotEmpty())
        <h2 class="h5 mt-5">{{ __('Pending invitations') }}</h2>
        <x-admin.table :headers="[__('Name'), __('Email'), __('Role'), __('Expires (IST)')]" :caption="__('Pending invitations')">
            @foreach ($this->pendingInvitations as $invitation)
                <tr wire:key="invitation-{{ $invitation->id }}">
                    <td>{{ $invitation->name }}</td>
                    <td>{{ $invitation->email }}</td>
                    <td>{{ \Illuminate\Support\Str::headline($invitation->role) }}</td>
                    <td>{{ $invitation->expires_at->timezone(config('oppam.display_timezone'))->format('d M Y, g:i a') }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif

    @can('staff.invite')
        <x-ui.modal name="invite-admin" :title="__('Invite a staff member')">
            <form wire:submit="invite" id="invite-form" novalidate>
                <x-ui.input :label="__('Name')" name="inviteName" wire:model="inviteName" required />
                <x-ui.input :label="__('Work email')" name="inviteEmail" type="email" wire:model="inviteEmail" required />
                <x-ui.select :label="__('Role')" name="inviteRole" wire:model="inviteRole" :placeholder="__('Choose a role')" required
                             :options="$this->roles->mapWithKeys(fn ($role) => [$role->name => \Illuminate\Support\Str::headline($role->name)])->all()"
                             :hint="__('You can only invite into a role whose permissions you hold.')" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button form="invite-form" loading="invite">{{ __('Send invitation') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    @can('staff.suspend')
        <x-admin.confirm-modal name="admin-status" :title="__('Change account status')" action="applyStatusChange" :confirm-label="__('Apply')">
            <p>{{ __('Suspending ends all of this admin’s sessions immediately. Reactivating also clears a two-factor lock.') }}</p>
        </x-admin.confirm-modal>
    @endcan
</div>
