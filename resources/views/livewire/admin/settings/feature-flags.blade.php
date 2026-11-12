{{--
    A15 feature flags (P8.4): what each switch does and whether it is on. Switching asks for a reason
    (audited); flags for features not built yet are listed, not switchable.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Feature flags') }}</h1>
            <p>{{ __('Switch features on or off without a deploy. Every change is recorded with your reason.') }}</p>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="promptToggle, confirmToggle">
        <x-admin.table :headers="[__('Feature'), __('What it does'), __('State'), '']" :caption="__('Feature flags')">
            @foreach ($flags as $flag)
                @php($on = \App\Support\Facades\Feature::active($flag))
                <tr wire:key="flag-{{ $flag->value }}">
                    <td>
                        <strong>{{ $flag->label() }}</strong>
                        <div class="small text-muted-brand"><code>{{ $flag->value }}</code></div>
                    </td>
                    <td class="small">{{ $flag->description() }}</td>
                    <td>
                        @if (! $flag->isBuilt())
                            <x-ui.badge variant="muted">{{ __('Not built yet') }}</x-ui.badge>
                        @else
                            <x-ui.badge :variant="$on ? 'success' : 'muted'">{{ $on ? __('On') : __('Off') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($canEdit && $flag->isBuilt())
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="promptToggle('{{ $flag->value }}')" loading="promptToggle" dusk="flag-{{ $flag->value }}">
                                {{ $on ? __('Switch off') : __('Switch on') }}
                            </x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    @if ($canEdit)
        <x-admin.confirm-modal name="flag-toggle" :title="$pendingFlag ? ($pendingState ? __('Switch on: :flag', ['flag' => $pendingFlag->label()]) : __('Switch off: :flag', ['flag' => $pendingFlag->label()])) : __('Change feature flag')"
                               action="confirmToggle" :confirm-label="$pendingState ? __('Switch on') : __('Switch off')" :danger="! $pendingState">
            @if ($pendingFlag)
                <p class="mb-0">{{ $pendingFlag->description() }}</p>
            @endif
        </x-admin.confirm-modal>
    @endif
</div>
