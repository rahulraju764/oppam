{{--
    A06 plan editor: the card (preview on the right), prices by duration, then the entitlement matrix
    with the consistency warning. Form controls are disabled for an admin without billing.plans.edit.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Edit :plan', ['plan' => $name]) }}</h1>
            <p><a href="{{ route('admin.billing.plans') }}" wire:navigate>{{ __('Back to plans') }}</a></p>
        </div>
    </div>

    @if ($warnings !== [])
        <div class="mb-4" data-dusk="plan-warnings">
            <x-ui.alert type="warning">
                <strong>{{ __('The card and what is enforced don’t match:') }}</strong>
                <ul class="mb-0">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <form wire:submit="saveCard" class="ui-card mb-4" novalidate>
                <h2 class="h6">{{ __('Card and prices') }}</h2>
                <fieldset @cannot('billing.plans.edit') disabled @endcannot>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Name')" name="name" id="plan-name" wire:model.live.debounce.400ms="name" maxlength="60" required />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-ui.input :label="__('Badge')" name="badge" id="plan-badge" wire:model.live.debounce.400ms="badge" maxlength="30" />
                        </div>
                        <div class="col-12">
                            <x-ui.textarea :label="__('Card lines, one per line')" name="features" id="plan-features" wire:model.live.debounce.400ms="features" error="displayFeatures" rows="7" />
                        </div>
                        @unless ($isFree)
                            <div class="col-12 col-md-6">
                                <x-ui.checkbox name="isFeatured" id="plan-featured" wire:model.live="isFeatured">{{ __('Featured (one plan at a time)') }}</x-ui.checkbox>
                            </div>
                            <div class="col-12 col-md-6">
                                <x-ui.checkbox name="isPurchasable" id="plan-purchasable" wire:model="isPurchasable" error="prices">{{ __('Can be bought') }}</x-ui.checkbox>
                            </div>
                            @foreach ($durations as $months)
                                <div class="col-6 col-md-3" wire:key="price-{{ $months }}">
                                    <x-ui.input :label="trans_choice(':count month, ₹|:count months, ₹', $months)" :name="'prices.'.$months.'.rupees'" :id="'plan-price-'.$months" inputmode="numeric"
                                                wire:model.live.debounce.400ms="prices.{{ $months }}.rupees" :error="'prices.'.$months" />
                                    <x-ui.checkbox :name="'prices.'.$months.'.active'" :id="'plan-price-on-'.$months" wire:model="prices.{{ $months }}.active">{{ __('On sale') }}</x-ui.checkbox>
                                </div>
                            @endforeach
                        @endunless
                    </div>
                </fieldset>
                @can('billing.plans.edit')
                    <div class="mt-3">
                        <x-ui.button type="submit" icon="fa-save" loading="saveCard" dusk="plan-save">{{ __('Save card and prices') }}</x-ui.button>
                    </div>
                @endcan
            </form>

            <form wire:submit="saveEntitlements" class="ui-card" novalidate>
                <h2 class="h6">{{ __('Enforced entitlements') }}</h2>
                <p class="text-muted">{{ __('An empty limit means unlimited. These apply as soon as you save.') }}</p>
                <fieldset @cannot('billing.plans.edit') disabled @endcannot>
                    @foreach ($entitlements as $entitlement)
                        <div class="row g-2 align-items-center mb-2" wire:key="rule-{{ $entitlement->value }}">
                            <div class="col-12 col-md-7">
                                <x-ui.checkbox :name="'rules.'.$entitlement->value.'.enabled'" :id="'rule-on-'.$entitlement->value" wire:model.live="rules.{{ $entitlement->value }}.enabled">{{ $entitlement->label() }}</x-ui.checkbox>
                            </div>
                            <div class="col-12 col-md-5">
                                @unless ($entitlement->isFlag())
                                    <x-ui.input :label="__('Limit')" :name="'rules.'.$entitlement->value.'.limit'" :id="'rule-limit-'.$entitlement->value" inputmode="numeric"
                                                wire:model.live.debounce.400ms="rules.{{ $entitlement->value }}.limit" :error="'rules.'.$entitlement->value" />
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </fieldset>
                @can('billing.plans.edit')
                    <x-ui.button type="submit" icon="fa-save" loading="saveEntitlements" dusk="plan-save-rules">{{ __('Save entitlements') }}</x-ui.button>
                @endcan
            </form>
        </div>

        <div class="col-12 col-lg-5">
            <h2 class="h6">{{ __('Preview') }}</h2>
            <div class="row">
                <x-pricing.card :plan="$preview" />
            </div>
        </div>
    </div>
</div>
