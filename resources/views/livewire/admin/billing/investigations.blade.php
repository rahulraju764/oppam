{{-- A06 reconciliation: what the daily job or a refund failure found that a person must look at. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Reconciliation') }}</h1>
            <p>{{ __('The gateway is compared with our records every day at 02:00 IST. Payments we can match activate on their own; the rest appear here.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Status')" name="status" id="inv-status" wire:model.live="status" :options="$statuses" />
            </div>
        </div>
    </div>

    <x-admin.table :headers="[__('What'), __('Order'), __('Details'), __('Found (IST)'), __('Outcome')]" :caption="__('Investigate rows')" :empty="$rows->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-check" :title="__('Nothing to investigate')" :message="__('Our records and the gateway agree.')" />
        </x-slot:emptyState>

        @foreach ($rows as $row)
            <tr wire:key="inv-{{ $row->id }}">
                <td>
                    <strong>{{ $row->type->label() }}</strong>
                    <div class="text-muted">{{ $row->type->hint() }}</div>
                </td>
                <td>
                    @if ($row->order)
                        <a href="{{ route('admin.billing.orders.show', ['order' => $row->order->code]) }}" wire:navigate>{{ $row->order->code }}</a>
                    @else
                        —
                    @endif
                </td>
                <td>
                    @foreach ($row->details as $key => $value)
                        <div>{{ str_replace('_', ' ', (string) $key) }}: {{ str_ends_with((string) $key, '_paise') ? '₹'.\App\ValueObjects\Money::paise((int) $value)->format() : $value }}</div>
                    @endforeach
                </td>
                <td>{{ $row->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                <td>
                    @if ($row->status === \App\Enums\InvestigationStatus::Open)
                        @can('billing.refund')
                            <x-ui.button size="sm" variant="outline" type="button" wire:click="confirm('{{ $row->id }}')" dusk="investigation-open">{{ __('Resolve') }}</x-ui.button>
                        @endcan
                    @else
                        {{ $row->resolution_note }} ({{ $row->resolvedBy?->name }})
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $rows->links() }}

    <x-admin.confirm-modal name="investigation-resolve" :title="__('Resolve this row')" action="resolve" reasonModel="note" :danger="false" :confirmLabel="__('Resolve')" />
</div>
