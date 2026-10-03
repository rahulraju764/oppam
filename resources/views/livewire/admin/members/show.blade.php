<div>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.members.index') }}" wire:navigate><i class="fa fa-angle-left" aria-hidden="true"></i> {{ __('All members') }}</a></p>
            <h1>{{ $profile->fullName() }} <span class="text-muted-brand">· {{ $profile->code }}</span></h1>
            <p>
                <x-ui.badge :variant="$member->status->badgeVariant()">{{ __('Account: :s', ['s' => $member->status->label()]) }}</x-ui.badge>
                <x-ui.badge :variant="$profile->status->badgeVariant()">{{ __('Profile: :s', ['s' => $profile->status->label()]) }}</x-ui.badge>
                @if ($profile->is_premium)<x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>@endif
                @if ($profile->is_verified)<x-ui.badge variant="verified" icon="fa-check">{{ __('ID verified') }}</x-ui.badge>@endif
            </p>
        </div>
    </div>

    @if ($isDeleted)
        <x-ui.alert type="warning">
            @if ($restorableUntil)
                {{ __('Deleted on :d. It can be restored until :u (IST); after that it is anonymised.', ['d' => $member->deleted_at?->timezone($timezone)->format('d M Y'), 'u' => $restorableUntil->timezone($timezone)->format('d M Y, g:i a')]) }}
            @else
                {{ __('Deleted and anonymised.') }}
            @endif
        </x-ui.alert>
    @endif

    <nav class="admin-tabs mb-3" aria-label="{{ __('Member sections') }}">
        @foreach (\App\Livewire\Admin\Members\Show::TABS as $key)
            <button type="button" class="admin-tab @if ($tab === $key) active @endif" wire:click="$set('tab', '{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif>
                {{ match ($key) {
                    'overview' => __('Overview'), 'profile' => __('Profile'), 'photos' => __('Photos'),
                    'subscriptions' => __('Subscriptions'), 'activity' => __('Activity'), 'notes' => __('Notes'), default => __('Timeline'),
                } }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="ui-card">
                    <h2 class="h6">{{ __('Account') }}</h2>
                    <dl class="mod-facts">
                        <dt>{{ __('Phone') }}</dt><dd>{{ $member->anonymised_at ? '—' : $member->phone }}</dd>
                        <dt>{{ __('Email') }}</dt><dd>{{ $member->email ?? '—' }} @if ($member->email && ! $member->email_verified_at)<span class="text-muted-brand">({{ __('not verified') }})</span>@endif</dd>
                        <dt>{{ __('Profile for') }}</dt><dd>{{ $member->created_for->label() }}</dd>
                        <dt>{{ __('Gender / age') }}</dt><dd>{{ $profile->gender->label() }}@if ($profile->age()) · {{ $profile->age() }}@endif</dd>
                        <dt>{{ __('Completeness') }}</dt><dd>{{ $profile->completeness }}%</dd>
                        <dt>{{ __('Registered') }}</dt><dd>{{ $member->created_at?->timezone($timezone)->format('d M Y, g:i a') }}</dd>
                        <dt>{{ __('Published') }}</dt><dd>{{ $profile->published_at?->timezone($timezone)->format('d M Y') ?? __('Not yet') }}</dd>
                        <dt>{{ __('Last active') }}</dt><dd>{{ $profile->last_active_at?->timezone($timezone)->format('d M Y, g:i a') ?? __('Never') }}</dd>
                        @if ($member->suspended_at)
                            <dt>{{ __('Suspended since') }}</dt><dd>{{ $member->suspended_at->timezone($timezone)->format('d M Y, g:i a') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
            <div class="col-12 col-lg-5">
                <div class="ui-card">
                    <h2 class="h6">{{ __('Quick actions') }}</h2>
                    <div class="d-flex flex-wrap gap-2">
                        @can('members.suspend')
                            @if ($isActive)
                                <x-ui.button size="sm" variant="danger" type="button" icon="fa-ban" x-on:click="$dispatch('open-modal', { name: 'member-suspend' })">{{ __('Suspend') }}</x-ui.button>
                            @elseif ($isSuspended)
                                <x-ui.button size="sm" type="button" icon="fa-check" x-on:click="$dispatch('open-modal', { name: 'member-reactivate' })">{{ __('Reactivate') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('members.edit')
                            @if ($canHide)
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-eye-slash" x-on:click="$dispatch('open-modal', { name: 'member-hide' })">{{ __('Hide profile') }}</x-ui.button>
                            @elseif ($canUnhide)
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-eye" x-on:click="$dispatch('open-modal', { name: 'member-unhide' })">{{ __('Unhide profile') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('billing.force_activate')
                            @if ($isActive && $member->hasVerifiedPhone())
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-gift" x-on:click="$dispatch('open-modal', { name: 'member-grant' })">{{ __('Grant complimentary plan') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('members.delete')
                            @if (! $isDeleted)
                                <x-ui.button size="sm" variant="danger" type="button" icon="fa-trash" x-on:click="$dispatch('open-modal', { name: 'member-delete' })">{{ __('Delete') }}</x-ui.button>
                            @elseif ($restorableUntil)
                                <x-ui.button size="sm" type="button" icon="fa-undo" x-on:click="$dispatch('open-modal', { name: 'member-restore' })">{{ __('Restore') }}</x-ui.button>
                            @endif
                        @endcan
                    </div>
                    <p class="small text-muted-brand mt-3 mb-0">{{ __('Every action asks for a reason, which is kept in the audit log. The member is never shown it.') }}</p>
                </div>
            </div>
        </div>

        {{-- Dialogs only for the actions this admin may take (each Action checks again). --}}
        @can('members.suspend')
        <x-admin.confirm-modal name="member-suspend" :title="__('Suspend :code', ['code' => $profile->code])" action="suspend" :confirmLabel="__('Suspend')">
            <p>{{ __('They are signed out everywhere, their profile leaves search, and any plan is paused. They get a short email asking them to contact support.') }}</p>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="member-reactivate" :title="__('Reactivate :code', ['code' => $profile->code])" action="reactivate" :confirmLabel="__('Reactivate')" :danger="false">
            <p>{{ __('The account can sign in again; the profile returns to its earlier status and paused plans resume.') }}</p>
        </x-admin.confirm-modal>
        @endcan
        @can('members.edit')
        <x-admin.confirm-modal name="member-hide" :title="__('Hide :code', ['code' => $profile->code])" action="hide" :confirmLabel="__('Hide profile')">
            <p>{{ __('The profile leaves search and profile pages. The member can still sign in.') }}</p>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="member-unhide" :title="__('Unhide :code', ['code' => $profile->code])" action="unhide" :confirmLabel="__('Unhide profile')" :danger="false" />
        @endcan
        @can('members.delete')
        <x-admin.confirm-modal name="member-delete" :title="__('Delete :code', ['code' => $profile->code])" action="delete" :confirmLabel="__('Delete member')">
            <p>{{ __('The account is closed now and can be restored for a limited time; after that it is anonymised for good. Type the member code to confirm.') }}</p>
            <x-ui.input :label="__('Member code')" name="confirmCode" id="member-delete-code" wire:model="confirmCode" autocomplete="off" required />
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="member-restore" :title="__('Restore :code', ['code' => $profile->code])" action="restore" :confirmLabel="__('Restore')" :danger="false" />
        @endcan
        @can('billing.force_activate')
        <x-admin.confirm-modal name="member-grant" :title="__('Grant a complimentary plan')" action="grant" :confirmLabel="__('Grant')" :danger="false">
            <div class="row g-3">
                <div class="col-7"><x-ui.select :label="__('Plan')" name="grantPlan" wire:model="grantPlan" :options="$grantablePlans" :placeholder="__('Choose…')" required /></div>
                <div class="col-5"><x-ui.input :label="__('Days')" name="grantDays" type="number" min="1" max="365" wire:model="grantDays" required /></div>
            </div>
        </x-admin.confirm-modal>
        @endcan
    @elseif ($tab === 'profile')
        <livewire:admin.members.tabs.profile-tab :code="$code" :key="'tab-profile-'.$code" lazy />
    @elseif ($tab === 'photos')
        <livewire:admin.members.tabs.photos-tab :code="$code" :key="'tab-photos-'.$code" lazy />
    @elseif ($tab === 'subscriptions')
        <livewire:admin.members.tabs.subscriptions-tab :code="$code" :key="'tab-subs-'.$code" lazy />
    @elseif ($tab === 'activity')
        <livewire:admin.members.tabs.activity-tab :code="$code" :key="'tab-activity-'.$code" lazy />
    @elseif ($tab === 'notes')
        <livewire:admin.members.tabs.notes-tab :code="$code" :key="'tab-notes-'.$code" lazy />
    @else
        <livewire:admin.members.tabs.timeline-tab :code="$code" :key="'tab-timeline-'.$code" lazy />
    @endif
</div>
