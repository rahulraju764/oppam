{{-- A06 coupons: percent / fixed, caps, per-member limit, validity, plans, first-time-only, minimum order. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Coupons') }}</h1>
            <p>{{ __('An order keeps the discount it was created with. A used coupon can be deactivated, not deleted.') }}</p>
        </div>
        @can('billing.coupons.edit')
            <x-ui.button type="button" icon="fa-plus" wire:click="create" loading="create" dusk="coupon-new">{{ __('New coupon') }}</x-ui.button>
        @endcan
    </div>

    <x-admin.table :headers="[__('Code'), __('Discount'), __('Used'), __('Valid (IST)'), __('Status'), __('Actions')]" :caption="__('Coupons')" :empty="$coupons->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-ticket" :title="__('No coupons yet')" :message="__('Create one to offer a discount at checkout.')" />
        </x-slot:emptyState>

        @foreach ($coupons as $coupon)
            <tr wire:key="coupon-{{ $coupon->code }}">
                <td><strong>{{ $coupon->code }}</strong></td>
                <td>{{ $coupon->type === \App\Enums\CouponType::Percent ? $coupon->value.'%' : '₹'.\App\ValueObjects\Money::paise($coupon->value)->format() }}</td>
                <td>{{ number_format($coupon->redemptions_count) }}{{ $coupon->max_redemptions !== null ? ' / '.number_format($coupon->max_redemptions) : '' }}</td>
                <td>
                    {{ $coupon->starts_at?->timezone($timezone)->format('d M Y') ?? __('Now') }} –
                    {{ $coupon->ends_at?->timezone($timezone)->format('d M Y') ?? __('No end') }}
                </td>
                <td><x-ui.badge :variant="$coupon->is_active ? 'success' : 'muted'">{{ $coupon->is_active ? __('Active') : __('Inactive') }}</x-ui.badge></td>
                <td class="text-nowrap">
                    @can('billing.coupons.edit')
                        <x-ui.button size="sm" variant="outline" type="button" wire:click="edit('{{ $coupon->code }}')">{{ __('Edit') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" type="button" wire:click="toggle('{{ $coupon->code }}')">{{ $coupon->is_active ? __('Deactivate') : __('Activate') }}</x-ui.button>
                        @if ($coupon->redemptions_count === 0)
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="delete('{{ $coupon->code }}')" wire:confirm="{{ __('Delete this coupon?') }}">{{ __('Delete') }}</x-ui.button>
                        @endif
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $coupons->links() }}

    @can('billing.coupons.edit')
    <x-ui.modal name="coupon-form" :title="$editing !== null ? __('Edit :code', ['code' => $editing]) : __('New coupon')">
        <form wire:submit="save" id="coupon-form" novalidate>
            <div class="row g-3">
                <div class="col-12 col-md-6"><x-ui.input :label="__('Code')" name="code" id="cp-code" wire:model="code" maxlength="30" required /></div>
                <div class="col-12 col-md-6">
                    <x-ui.select :label="__('Type')" name="type" id="cp-type" wire:model.live="type" :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />
                </div>
                <div class="col-6"><x-ui.input :label="$type === 'PERCENT' ? __('Percent') : __('Amount, ₹')" name="value" id="cp-value" inputmode="decimal" wire:model="value" required /></div>
                @if ($type === 'PERCENT')
                    <div class="col-6"><x-ui.input :label="__('Largest discount, ₹')" name="maxDiscount" id="cp-max" inputmode="decimal" wire:model="maxDiscount" /></div>
                @endif
                <div class="col-6"><x-ui.input :label="__('Minimum order, ₹')" name="minOrder" id="cp-min" inputmode="decimal" wire:model="minOrder" /></div>
                <div class="col-6"><x-ui.input :label="__('Total uses')" name="maxRedemptions" id="cp-total" inputmode="numeric" wire:model="maxRedemptions" :hint="__('Empty: no limit')" /></div>
                <div class="col-6"><x-ui.input :label="__('Uses per member')" name="perMemberLimit" id="cp-member" inputmode="numeric" wire:model="perMemberLimit" required /></div>
                <div class="col-12 col-md-6"><x-ui.input :label="__('Starts (IST)')" name="startsAt" id="cp-start" type="datetime-local" wire:model="startsAt" /></div>
                <div class="col-12 col-md-6"><x-ui.input :label="__('Ends (IST)')" name="endsAt" id="cp-end" type="datetime-local" wire:model="endsAt" /></div>
                <div class="col-12">
                    <x-ui.checkbox name="firstTimeOnly" id="cp-first" wire:model="firstTimeOnly">{{ __('First-time buyers only') }}</x-ui.checkbox>
                </div>
                <div class="col-12">
                    <fieldset>
                        <legend class="h6">{{ __('Plans (none ticked: every paid plan)') }}</legend>
                        @foreach ($plans as $plan)
                            <x-ui.checkbox :name="'planCodes.'.$plan->value" :id="'cp-plan-'.$plan->value" :value="$plan->value" wire:model="planCodes" error="planCodes">{{ ucfirst(strtolower($plan->value)) }}</x-ui.checkbox>
                        @endforeach
                    </fieldset>
                </div>
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="coupon-form" loading="save" dusk="coupon-save">{{ __('Save coupon') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endcan
</div>
