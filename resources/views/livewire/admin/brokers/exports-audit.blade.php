{{--
    A07 export audit (§11A B.12, R-A07-7): read-only list of every export attempt; unusual rows are
    highlighted with a text badge (never colour alone). Embedded in a bureau's page it has no
    bureau filter and no page heading.
--}}
<div>
    @unless ($embedded)
        <div class="admin-page-head">
            <div>
                <h1>{{ __('Export audit') }}</h1>
                <p>{{ __('Every biodata and photo export by every bureau — allowed and refused. Nothing here can be edited or deleted.') }}</p>
            </div>
        </div>
    @endunless

    <div class="ui-card mb-3">
        <div class="row g-3">
            @unless ($embedded)
                <div class="col-6 col-lg-2">
                    <x-ui.input :label="__('Bureau code')" name="broker" id="ea-broker" wire:model.live.debounce.400ms="broker" placeholder="BRK1001" />
                </div>
            @endunless
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('Staff code')" name="staff" id="ea-staff" wire:model.live.debounce.400ms="staff" placeholder="STF1001" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('Profile code')" name="profile" id="ea-profile" wire:model.live.debounce.400ms="profile" placeholder="OPM12345" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.select :label="__('Type')" name="type" id="ea-type" wire:model.live="type" :options="$types" :placeholder="__('Any')" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.select :label="__('Result')" name="allowed" id="ea-allowed" wire:model.live="allowed" :placeholder="__('Any')"
                             :options="['yes' => __('Allowed'), 'no' => __('Refused')]" />
            </div>
            <div class="col-6 col-lg-1">
                <x-ui.input :label="__('From')" name="from" id="ea-from" type="date" wire:model.live="from" />
            </div>
            <div class="col-6 col-lg-1">
                <x-ui.input :label="__('To')" name="to" id="ea-to" type="date" wire:model.live="to" />
            </div>
        </div>
        <div class="mt-2">
            <x-ui.button size="sm" variant="ghost" type="button" wire:click="clearFilters" icon="fa-times">{{ __('Clear filters') }}</x-ui.button>
        </div>
    </div>

    @if ($unknown !== [])
        <x-ui.alert type="warning">
            {{ __('No such :what — check the code.', ['what' => collect($unknown)->map(fn (string $k): string => match ($k) { 'broker' => __('bureau'), 'staff' => __('staff login'), default => __('profile') })->implode(', ')]) }}
        </x-ui.alert>
    @endif

    <div wire:loading.class="opacity-50" wire:target="broker,staff,profile,type,allowed,from,to,clearFilters,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="array_values(array_filter([__('When'), $embedded ? null : __('Bureau'), __('Staff'), __('Profile'), __('Type'), __('Result'), __('Pattern')]))"
                       :caption="__('Export audit')" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-download" :title="__('No exports')" :message="__('No export attempt matches these filters.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                @php($anomaly = \App\Queries\Admin\BrokerExportAudit::anomaly($row))
                <tr wire:key="ex-{{ $row->id }}">
                    <td>{{ $row->created_at->timezone($timezone)->format('d M Y, H:i') }}</td>
                    @unless ($embedded)
                        <td><a href="{{ route('admin.brokers.show', ['broker' => $row->getAttribute('broker_code')]) }}" wire:navigate>{{ $row->getAttribute('broker_code') }}</a></td>
                    @endunless
                    <td>
                        {{ $row->getAttribute('staff_code') ?? '—' }}
                        @if ($row->getAttribute('staff_role'))
                            <div class="small text-muted-brand">{{ \App\Enums\BureauRole::from((string) $row->getAttribute('staff_role'))->label() }}</div>
                        @endif
                    </td>
                    <td>{{ $row->getAttribute('profile_code') ?? '—' }}</td>
                    <td>{{ $row->export_type->label() }}</td>
                    <td>
                        @if ($row->allowed)
                            <x-ui.badge variant="success">{{ __('Allowed') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">{{ __('Refused') }}</x-ui.badge>
                        @endif
                    </td>
                    <td>
                        @if ($anomaly === 'burst')
                            <x-ui.badge variant="warning" icon="fa-exclamation-triangle">{{ __('Many exports in an hour') }}</x-ui.badge>
                        @elseif ($anomaly === 'repeat')
                            <x-ui.badge variant="warning" icon="fa-repeat">{{ __('Exported again soon') }}</x-ui.badge>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $rows->links() }}
    </div>
</div>
