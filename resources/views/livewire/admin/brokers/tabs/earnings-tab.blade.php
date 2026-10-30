<div>
    <div class="row g-3 mb-3">
        @foreach ($statuses as $case)
            <div class="col-6 col-lg-3">
                <x-admin.stat-card :label="$case->label()" :value="($totals[$case->value]['amount'] ?? \App\ValueObjects\Money::paise(0))->formatWithSymbol()"
                                   :hint="trans_choice(':count profile|:count profiles', $totals[$case->value]['n'] ?? 0)" />
            </div>
        @endforeach
    </div>

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Show')" name="ledgerStatus" id="ledger-status" wire:model.live="status" :options="\App\Enums\ReferralStatus::options()" :placeholder="__('All')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="status,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="[__('Profile'), __('Type'), __('Order'), __('Base'), __('Rate'), __('Commission'), __('Status'), __('Earned (IST)'), __('Payout')]"
                       :caption="__('Commission ledger')" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-inr" :title="__('No commission yet')" :message="__('Referred and managed profiles appear here once they are attributed to this bureau.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                <tr wire:key="ledger-{{ $row->id }}">
                    <td>{{ $row->profile?->code ?? '—' }}</td>
                    <td>{{ $row->source->label() }}</td>
                    <td>{{ $row->order?->code ?? '—' }}</td>
                    <td>{{ $row->base_paise !== null ? \App\ValueObjects\Money::paise($row->base_paise)->formatWithSymbol() : '—' }}</td>
                    <td>{{ $row->commission_bps !== null ? rtrim(rtrim(number_format($row->commission_bps / 100, 2, '.', ''), '0'), '.').'%' : '—' }}</td>
                    <td>{{ $row->commission()->formatWithSymbol() }}</td>
                    <td>
                        <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                        @if ($row->void_reason)<div class="small text-muted-brand">{{ $row->void_reason->label() }}</div>@endif
                    </td>
                    <td>{{ $row->earned_at?->timezone($timezone)->format('d M Y') ?? '—' }}</td>
                    <td>{{ $row->payoutItem?->run?->code ?? '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $rows->links() }}
    </div>
</div>
