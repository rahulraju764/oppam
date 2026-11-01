{{--
    A06 plans: price per duration, running members, status. Deactivate takes a plan out of the
    catalogue; delete only works for a plan nothing refers to.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Plans') }}</h1>
            <p>{{ __('A price change reaches new orders only. An entitlement change applies at once.') }}</p>
        </div>
    </div>

    <x-admin.table :headers="[__('Plan'), __('Prices before GST'), __('Running'), __('Status'), __('Actions')]" :caption="__('Membership plans')" :empty="$plans->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-tags" :title="__('No plans')" :message="__('Run the plans seeder to add them.')" />
        </x-slot:emptyState>

        @foreach ($plans as $plan)
            <tr wire:key="plan-{{ $plan->code->value }}">
                <td>
                    <a href="{{ route('admin.billing.plans.edit', ['plan' => $plan->code->value]) }}" wire:navigate><strong>{{ $plan->name }}</strong></a>
                    @if ($plan->is_featured)
                        <x-ui.badge variant="success">{{ __('Featured') }}</x-ui.badge>
                    @endif
                </td>
                <td>
                    @forelse ($plan->prices as $price)
                        <div @class(['text-muted' => ! $price->is_active])>{{ trans_choice(':count month|:count months', $price->duration_months) }}: ₹{{ $price->price()->format() }}</div>
                    @empty
                        {{ __('No charge') }}
                    @endforelse
                </td>
                <td>{{ number_format((int) $plan->getAttribute('running_count')) }}</td>
                <td>
                    <x-ui.badge :variant="$plan->is_active ? 'success' : 'muted'">{{ $plan->is_active ? __('Active') : __('Inactive') }}</x-ui.badge>
                </td>
                <td class="text-nowrap">
                    <x-ui.button size="sm" variant="outline" :href="route('admin.billing.plans.edit', ['plan' => $plan->code->value])">{{ __('Edit') }}</x-ui.button>
                    @can('billing.plans.edit')
                        @if ($plan->code !== \App\Enums\PlanCode::Free)
                            @if ($plan->is_active)
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $plan->code->value }}', 'plan-deactivate')">{{ __('Deactivate') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $plan->code->value }}', 'plan-activate')">{{ __('Activate') }}</x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $plan->code->value }}', 'plan-delete')">{{ __('Delete') }}</x-ui.button>
                        @endif
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @can('billing.plans.edit')
    <x-admin.confirm-modal name="plan-deactivate" :title="__('Deactivate :plan', ['plan' => $selected])" action="deactivate" :confirmLabel="__('Deactivate')">
        <p>{{ __('It leaves the plans page. Members running it keep it until it ends.') }}</p>
    </x-admin.confirm-modal>
    <x-admin.confirm-modal name="plan-activate" :title="__('Activate :plan', ['plan' => $selected])" action="activate" :danger="false" :confirmLabel="__('Activate')">
        <p>{{ __('It returns to the plans page if it is purchasable.') }}</p>
    </x-admin.confirm-modal>
    <x-admin.confirm-modal name="plan-delete" :title="__('Delete :plan', ['plan' => $selected])" action="delete" :confirmLabel="__('Delete')">
        <p>{{ __('Only a plan with no orders or subscriptions can be deleted.') }}</p>
    </x-admin.confirm-modal>
    @endcan
</div>
