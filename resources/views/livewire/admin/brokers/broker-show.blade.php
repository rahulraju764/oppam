{{--
    A07 bureau detail (§11A B.12): header, tabs, Overview (facts, terms, quick actions with a typed
    reason). Buttons are hidden by @can; every Action checks the permission again.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.brokers.index') }}" wire:navigate><i class="fa fa-angle-left" aria-hidden="true"></i> {{ __('All brokers') }}</a></p>
            <h1>{{ $broker->displayName() }} <span class="text-muted-brand">· {{ $broker->code }}</span></h1>
            <p>
                <x-ui.badge :variant="$broker->status->badgeVariant()">{{ __('Status: :s', ['s' => $broker->status->label()]) }}</x-ui.badge>
                <x-ui.badge :variant="$broker->kyc_status->badgeVariant()">{{ __('KYC: :s', ['s' => $broker->kyc_status->label()]) }}</x-ui.badge>
                @if ($broker->imports_paused_at)<x-ui.badge variant="warning" icon="fa-pause">{{ __('Imports paused') }}</x-ui.badge>@endif
                @if ($broker->payouts_held_at)<x-ui.badge variant="danger" icon="fa-lock">{{ __('Payouts held') }}</x-ui.badge>@endif
                @if ($broker->creation_paused_at)<x-ui.badge variant="danger" icon="fa-pause">{{ __('Adding profiles paused') }}</x-ui.badge>@endif
            </p>
        </div>
    </div>

    @if ($waitingForOwner)
        <x-ui.alert type="info">
            {{ __('Created by an admin — waiting for the owner to apply with :phone. Send them this link:', ['phone' => $broker->phone]) }}
            <code class="d-inline-block text-break">{{ $applyUrl }}</code>
        </x-ui.alert>
    @endif

    <nav class="admin-tabs mb-3" aria-label="{{ __('Bureau sections') }}">
        @foreach ($tabs as $key)
            <button type="button" class="admin-tab @if ($tab === $key) active @endif" wire:click="$set('tab', '{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif dusk="broker-tab-{{ $key }}">
                {{ match ($key) {
                    'overview' => __('Overview'), 'referrals' => __('Referrals'), 'profiles' => __('Managed profiles'), 'staff' => __('Staff'),
                    'imports' => __('Imports'), 'earnings' => __('Earnings'), 'payouts' => __('Payouts'), 'exports' => __('Exports'),
                    'disputes' => __('Disputes'), default => __('Activity'),
                } }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <livewire:admin.brokers.signals-panel :code="$code" :key="'signals-'.$code" />
        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="ui-card mb-4">
                    <h2 class="h6">{{ __('Bureau') }}</h2>
                    <dl class="mod-facts">
                        <dt>{{ __('Owner') }}</dt><dd>{{ $broker->name }}</dd>
                        <dt>{{ __('Phone') }}</dt><dd>{{ $broker->phone }}</dd>
                        <dt>{{ __('Email') }}</dt><dd>{{ $broker->email ?? '—' }}</dd>
                        <dt>{{ __('District') }}</dt><dd>{{ $broker->district?->label ?? '—' }}</dd>
                        <dt>{{ __('Applied') }}</dt><dd>{{ $broker->created_at->timezone($timezone)->format('d M Y') }}</dd>
                        <dt>{{ __('Verified') }}</dt><dd>{{ $broker->activated_at?->timezone($timezone)->format('d M Y') ?? __('Not yet') }}</dd>
                        <dt>{{ __('PAN') }}</dt><dd>{{ $broker->pan_last4 ? '•••••••'.$broker->pan_last4 : '—' }}</dd>
                        @if ($broker->status_reason)
                            <dt>{{ __('Status reason') }}</dt><dd>{{ $broker->status_reason }}</dd>
                        @endif
                        @if ($broker->payouts_hold_reason)
                            <dt>{{ __('Payout hold') }}</dt><dd>{{ $broker->payouts_hold_reason }}</dd>
                        @endif
                        @if ($broker->creation_paused_reason)
                            <dt>{{ __('Adding profiles paused') }}</dt><dd>{{ $broker->creation_paused_reason }}</dd>
                        @endif
                    </dl>
                </div>

                <div class="ui-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h6">{{ __('Terms & limits') }}</h2>
                        @canany(['brokers.edit', 'brokers.commission_bps'])
                            <x-ui.button size="sm" variant="outline" type="button" icon="fa-pencil" x-on:click="$dispatch('open-modal', { name: 'broker-terms' })" dusk="broker-terms-open">{{ __('Edit') }}</x-ui.button>
                        @endcanany
                    </div>
                    <dl class="mod-facts">
                        <dt>{{ __('Commission') }}</dt><dd>{{ rtrim(rtrim(number_format($broker->commission_bps / 100, 2, '.', ''), '0'), '.') }}% {{ __('of the plan price before GST') }}</dd>
                        <dt>{{ __('Interest allowance') }}</dt><dd>{{ __(':n per month (managed profiles)', ['n' => $broker->interest_allowance_monthly]) }}</dd>
                        <dt>{{ __('Staff seats') }}</dt><dd>{{ __(':used used of :limit', ['used' => $seatsUsed, 'limit' => $broker->staff_seat_limit]) }}</dd>
                        <dt>{{ __('Import cap') }}</dt><dd>{{ __(':n rows per day', ['n' => $broker->import_daily_cap]) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="ui-card">
                    <h2 class="h6">{{ __('Quick actions') }}</h2>
                    <div class="d-flex flex-wrap gap-2">
                        @can('brokers.edit')
                            @if ($canSuspend)
                                <x-ui.button size="sm" variant="danger" type="button" icon="fa-ban" x-on:click="$dispatch('open-modal', { name: 'broker-suspend' })" dusk="broker-suspend-open">{{ __('Suspend') }}</x-ui.button>
                            @endif
                            @if ($canReactivate)
                                <x-ui.button size="sm" type="button" icon="fa-check" x-on:click="$dispatch('open-modal', { name: 'broker-reactivate' })">{{ __('Reactivate') }}</x-ui.button>
                            @endif
                            @if ($canDeactivate)
                                <x-ui.button size="sm" variant="danger" type="button" icon="fa-power-off" x-on:click="$dispatch('open-modal', { name: 'broker-deactivate' })" dusk="broker-deactivate-open">{{ __('Deactivate') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('brokers.edit')
                            @if ($broker->creation_paused_at)
                                <x-ui.button size="sm" type="button" icon="fa-play" x-on:click="$dispatch('open-modal', { name: 'broker-resume-creation' })">{{ __('Allow adding profiles') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-pause" x-on:click="$dispatch('open-modal', { name: 'broker-pause-creation' })">{{ __('Pause adding profiles') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('brokers.kyc')
                            @if ($broker->kyc_status === \App\Enums\BrokerKycStatus::Verified)
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-id-card-o" x-on:click="$dispatch('open-modal', { name: 'broker-revoke-kyc' })" dusk="broker-revoke-kyc-open">{{ __('Revoke KYC') }}</x-ui.button>
                            @endif
                        @endcan
                        @can('brokers.payout')
                            @if ($broker->payouts_held_at)
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-unlock" x-on:click="$dispatch('open-modal', { name: 'broker-release' })">{{ __('Release payouts') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="outline" type="button" icon="fa-lock" x-on:click="$dispatch('open-modal', { name: 'broker-hold' })">{{ __('Hold payouts') }}</x-ui.button>
                            @endif
                        @endcan
                    </div>
                    <p class="small text-muted-brand mt-3 mb-0">{{ __('Every action asks for a reason, which is kept in the audit log. The bureau never sees it.') }}</p>
                </div>
            </div>
        </div>

        @canany(['brokers.edit', 'brokers.commission_bps'])
            <x-ui.modal name="broker-terms" :title="__('Edit terms of :code', ['code' => $broker->code])">
                <div class="row g-3">
                    <div class="col-6">
                        <x-ui.input :label="__('Commission (basis points)')" name="commissionBps" id="bt-commission" type="number" min="0" max="10000" wire:model="commissionBps"
                                    :hint="__('1000 = 10 %. Applies to commission earned from now on.')" :disabled="! auth('admin')->user()?->can('brokers.commission_bps')" />
                    </div>
                    <div class="col-6">
                        <x-ui.input :label="__('Interests per month')" name="interestAllowance" id="bt-interests" type="number" min="0" max="10000" wire:model="interestAllowance"
                                    :disabled="! auth('admin')->user()?->can('brokers.edit')" />
                    </div>
                    <div class="col-6">
                        <x-ui.input :label="__('Staff seats')" name="seatLimit" id="bt-seats" type="number" min="0" max="100" wire:model="seatLimit"
                                    :hint="__('Lowering it never deactivates anyone.')" :disabled="! auth('admin')->user()?->can('brokers.edit')" />
                    </div>
                    <div class="col-6">
                        <x-ui.input :label="__('Import rows per day')" name="importDailyCap" id="bt-cap" type="number" min="0" max="20000" wire:model="importDailyCap"
                                    :disabled="! auth('admin')->user()?->can('brokers.edit')" />
                    </div>
                </div>
                <x-ui.textarea :label="__('Reason (recorded in the audit log)')" name="reason" id="bt-reason" wire:model="reason" rows="2" required />
                <x-slot:footer>
                    <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" wire:click="saveTerms" loading="saveTerms" dusk="broker-terms-save">{{ __('Save terms') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endcanany

        @can('brokers.edit')
            <x-admin.confirm-modal name="broker-suspend" :title="__('Suspend :code', ['code' => $broker->code])" action="suspend" :confirmLabel="__('Suspend')">
                <p>{{ __('The owner and every staff login are signed out now and can’t sign in until you reactivate. They earn nothing new and can’t create, import or export. History is kept.') }}</p>
            </x-admin.confirm-modal>
            <x-admin.confirm-modal name="broker-reactivate" :title="__('Reactivate :code', ['code' => $broker->code])" action="reactivate" :confirmLabel="__('Reactivate')" :danger="false">
                <p>{{ __('The bureau and its staff can sign in again.') }}</p>
            </x-admin.confirm-modal>
            <x-admin.confirm-modal name="broker-deactivate" :title="__('Deactivate :code', ['code' => $broker->code])" action="deactivate" :confirmLabel="__('Deactivate for good')">
                <p>{{ __('The bureau is closed for good: the owner and every staff login are signed out now and can’t come back. History is kept.') }}</p>
            </x-admin.confirm-modal>
        @endcan
        @can('brokers.edit')
            <x-admin.confirm-modal name="broker-pause-creation" :title="__('Pause adding profiles at :code', ['code' => $broker->code])" action="pauseCreation" :confirmLabel="__('Pause')">
                <p>{{ __('Nobody in the bureau can add or import profiles until you allow it again. Signing in, the client inbox, notes, exports and payouts keep working.') }}</p>
            </x-admin.confirm-modal>
            <x-admin.confirm-modal name="broker-resume-creation" :title="__('Allow adding profiles at :code', ['code' => $broker->code])" action="resumeCreation" :confirmLabel="__('Allow')" :danger="false" />
        @endcan
        @can('brokers.kyc')
            <x-admin.confirm-modal name="broker-revoke-kyc" :title="__('Revoke KYC of :code', ['code' => $broker->code])" action="revokeKyc" :confirmLabel="__('Revoke KYC')">
                <p>{{ __('The bureau keeps its profiles, referrals and logins, but nobody can create, import or export until the owner sends KYC again and it is approved. It is left out of payout runs meanwhile.') }}</p>
            </x-admin.confirm-modal>
        @endcan
        @can('brokers.payout')
            <x-admin.confirm-modal name="broker-hold" :title="__('Hold payouts to :code', ['code' => $broker->code])" action="holdPayouts" :confirmLabel="__('Hold payouts')">
                <p>{{ __('The bureau keeps working and earning, but is left out of payout runs until you release it.') }}</p>
            </x-admin.confirm-modal>
            <x-admin.confirm-modal name="broker-release" :title="__('Release payouts to :code', ['code' => $broker->code])" action="releasePayouts" :confirmLabel="__('Release')" :danger="false" />
        @endcan
    @elseif ($tab === 'referrals')
        <livewire:admin.brokers.tabs.referrals-tab :code="$code" :key="'tab-referrals-'.$code" lazy />
    @elseif ($tab === 'profiles')
        <livewire:admin.brokers.tabs.profiles-tab :code="$code" :key="'tab-profiles-'.$code" lazy />
    @elseif ($tab === 'staff')
        <livewire:admin.brokers.tabs.staff-tab :code="$code" :key="'tab-staff-'.$code" lazy />
    @elseif ($tab === 'imports')
        <livewire:admin.brokers.imports-monitor :bureau="$code" :key="'tab-imports-'.$code" lazy />
    @elseif ($tab === 'earnings')
        <livewire:admin.brokers.tabs.earnings-tab :code="$code" :key="'tab-earnings-'.$code" lazy />
    @elseif ($tab === 'payouts')
        <livewire:admin.brokers.tabs.payouts-tab :code="$code" :key="'tab-payouts-'.$code" lazy />
    @elseif ($tab === 'exports')
        <livewire:admin.brokers.exports-audit :bureau="$code" :key="'tab-exports-'.$code" lazy />
    @elseif ($tab === 'disputes')
        <livewire:admin.brokers.disputes :bureau="$code" :key="'tab-disputes-'.$code" lazy />
    @else
        <livewire:admin.brokers.tabs.activity-tab :code="$code" :key="'tab-activity-'.$code" lazy />
    @endif
</div>
