{{-- A06 subscription views and the complimentary grant. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Subscriptions') }}</h1>
        </div>
        @can('billing.force_activate')
            <x-ui.button type="button" icon="fa-gift" x-on:click="$dispatch('open-modal', { name: 'plan-grant' })" dusk="plan-grant-open">{{ __('Grant complimentary') }}</x-ui.button>
        @endcan
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Show')" name="view" id="sub-view" wire:model.live="view" :options="$views" />
            </div>
        </div>
    </div>

    <x-admin.table :headers="[__('Member'), __('Plan'), __('Source'), __('Status'), __('Starts (IST)'), __('Ends (IST)')]" :caption="__('Subscriptions')" :empty="$subscriptions->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-credit-card" :title="__('Nothing here')" :message="__('No subscription matches this view.')" />
        </x-slot:emptyState>

        @foreach ($subscriptions as $subscription)
            <tr wire:key="sub-{{ $subscription->id }}">
                <td>{{ $subscription->profile?->code }}</td>
                <td>{{ $subscription->plan?->name }}</td>
                <td>{{ $subscription->source->label() }}</td>
                <td>{{ $subscription->status->label() }}</td>
                <td>{{ $subscription->starts_at->timezone($timezone)->format('d M Y') }}</td>
                <td>{{ $subscription->ends_at->timezone($timezone)->format('d M Y') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $subscriptions->links() }}

    @can('billing.force_activate')
        <x-admin.confirm-modal name="plan-grant" :title="__('Grant a complimentary plan')" action="grant" :danger="false" :confirmLabel="__('Grant')">
            <div class="row g-3">
                <div class="col-12"><x-ui.input :label="__('Profile code')" name="grantCode" id="grant-code" wire:model="grantCode" required /></div>
                <div class="col-6">
                    <x-ui.select :label="__('Plan')" name="grantPlan" id="grant-plan" wire:model="grantPlan" :options="collect($plans)->mapWithKeys(fn ($p) => [$p->value => ucfirst(strtolower($p->value))])->all()" />
                </div>
                <div class="col-6"><x-ui.input :label="__('Days')" name="grantDays" id="grant-days" inputmode="numeric" wire:model="grantDays" :hint="__('Up to :max', ['max' => $maxDays])" required /></div>
            </div>
        </x-admin.confirm-modal>
    @endcan
</div>
