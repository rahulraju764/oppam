<div>
    <x-admin.table :headers="[__('Run'), __('Period'), __('Earned'), __('Clawback'), __('Carried in'), __('TDS'), __('Net'), __('Status'), __('UTR'), '']"
                   :caption="__('Payouts')" :empty="$items->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-bank" :title="__('No payouts yet')" :message="__('This bureau has not been in a payout run.')" />
        </x-slot:emptyState>

        @foreach ($items as $item)
            <tr wire:key="pi-{{ $item->id }}">
                <td>
                    @can('brokers.payout')
                        <a href="{{ route('admin.brokers.payouts.show', ['run' => $item->run?->code]) }}" wire:navigate>{{ $item->run?->code }}</a>
                    @else
                        {{ $item->run?->code }}
                    @endcan
                </td>
                <td>{{ $item->run?->label() }}</td>
                <td>{{ \App\ValueObjects\Money::paise($item->gross_paise)->formatWithSymbol() }}</td>
                <td>{{ $item->clawback_paise > 0 ? '−'.\App\ValueObjects\Money::paise($item->clawback_paise)->formatWithSymbol() : '—' }}</td>
                <td>{{ $item->carried_in_paise === 0 ? '—' : ($item->carried_in_paise < 0 ? '−' : '').\App\ValueObjects\Money::paise(abs($item->carried_in_paise))->formatWithSymbol() }}</td>
                <td>{{ $item->tds_paise > 0 ? \App\ValueObjects\Money::paise($item->tds_paise)->formatWithSymbol() : '—' }}</td>
                <td>
                    <strong>{{ $item->net()->formatWithSymbol() }}</strong>
                    @if ($item->status === \App\Enums\PayoutItemStatus::CarriedForward)
                        <div class="small text-muted-brand">{{ __('Carried: :amount', ['amount' => ($item->carried_forward_paise < 0 ? '−' : '').\App\ValueObjects\Money::paise(abs($item->carried_forward_paise))->formatWithSymbol()]) }}</div>
                    @endif
                </td>
                <td><x-ui.badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-ui.badge></td>
                <td>{{ $item->utr ?? '—' }}</td>
                <td class="text-end">
                    @if ($item->status === \App\Enums\PayoutItemStatus::Paid)
                        @can('brokers.payout')
                            <x-ui.button size="sm" variant="outline" icon="fa-file-pdf-o"
                                         :href="route('admin.brokers.payouts.statement', ['run' => $item->run?->code, 'broker' => $broker->code])">{{ __('Statement') }}</x-ui.button>
                        @endcan
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $items->links() }}
</div>
