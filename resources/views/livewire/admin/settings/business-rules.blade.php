{{--
    A15 business rules (P8.4): every rule from the SettingKey enum, grouped by section. A change takes
    a reason, is audited and applies at once (no deploy).
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Business rules') }}</h1>
            <p>{{ __('Limits, thresholds and timings the site uses. Changes apply immediately and are recorded in the audit log with your reason.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-3">
        <x-ui.input :label="__('Find a rule')" name="ruleSearch" id="rule-search" type="search" wire:model.live.debounce.400ms="q"
                    :placeholder="__('e.g. interest, OTP, photo')" autocomplete="off" />
    </div>

    <div wire:loading.class="is-loading" wire:target="q, edit, save">
        @forelse ($sections as $section => $keys)
            <h2 class="h5 mt-4 mb-2">{{ $section }}</h2>
            <x-admin.table :headers="[__('Rule'), __('Value'), '']" :caption="$section">
                @foreach ($keys as $key)
                    @php($current = $values[$key->value])
                    <tr wire:key="rule-{{ $key->value }}">
                        <td>
                            <strong>{{ $key->label() }}</strong>
                            <div class="small text-muted-brand"><code>{{ $key->value }}</code></div>
                        </td>
                        <td class="text-nowrap">
                            @if ($key->inputKind() === 'number')
                                {{ number_format((int) $current) }} {{ $key->unit() }}
                            @else
                                {{ \Illuminate\Support\Str::limit((string) $current, 60) ?: '—' }}
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($canEdit)
                                <x-ui.button type="button" variant="secondary" size="sm" wire:click="edit('{{ $key->value }}')" loading="edit" dusk="rule-edit-{{ $key->value }}">
                                    {{ __('Change') }}
                                </x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @empty
            <x-ui.empty-state icon="fa-search" :title="__('No rule matches “:q”', ['q' => $q])" :message="__('Try another word, or clear the search.')" />
        @endforelse
    </div>

    @if ($canEdit)
        <x-admin.confirm-modal name="rule-edit" :title="$editingKey ? $editingKey->label() : __('Change rule')" action="save" :confirm-label="__('Save rule')" :danger="false">
            @if ($editingKey)
                @if ($editingKey->inputKind() === 'number')
                    <x-ui.input type="number" :label="__('Value').($editingKey->unit() !== '' ? ' ('.$editingKey->unit().')' : '')" name="ruleValue" id="rule-value"
                                wire:model="value" inputmode="numeric" error="value" :hint="$limits ? __('Allowed: :range', ['range' => $limits]) : null" required />
                @elseif ($editingKey->inputKind() === 'textarea')
                    <x-ui.textarea :label="__('Value')" name="ruleValue" id="rule-value" wire:model="value" error="value" rows="3" />
                @else
                    <x-ui.input :label="__('Value')" name="ruleValue" id="rule-value" wire:model="value" error="value" />
                @endif
                <p class="small text-muted-brand mt-1"><code>{{ $editingKey->value }}</code></p>
            @endif
        </x-admin.confirm-modal>
    @endif
</div>
