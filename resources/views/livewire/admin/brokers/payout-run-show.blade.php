{{--
    A07 payout run (§11A B.10): preview of every bureau's line, approval (second approver above the
    threshold — R-A07-4), then per line "mark paid" with the UTR or "roll forward"; statements.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.brokers.payouts') }}" wire:navigate><i class="fa fa-angle-left" aria-hidden="true"></i> {{ __('All payout runs') }}</a></p>
            <h1>{{ $run->code }} <span class="text-muted-brand">· {{ $run->label() }}</span></h1>
            <p>
                <x-ui.badge :variant="$run->status->badgeVariant()">{{ $run->status->label() }}</x-ui.badge>
                @if ($needsSecond)<x-ui.badge variant="warning" icon="fa-users">{{ __('Needs a second approver') }}</x-ui.badge>@endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($run->status === \App\Enums\PayoutRunStatus::Draft)
                <x-ui.button type="button" icon="fa-check" wire:click="approve" loading="approve" dusk="payout-run-approve"
                             wire:confirm="{{ __('Approve this payout run? Amounts are fixed from now on.') }}">
                    {{ $run->approved_by_admin_id ? __('Give second approval') : __('Approve') }}
                </x-ui.button>
                <x-ui.button type="button" variant="outline" icon="fa-trash" x-on:click="$dispatch('open-modal', { name: 'payout-discard' })">{{ __('Discard draft') }}</x-ui.button>
            @endif
        </div>
    </div>

    <div class="ui-card mb-3">
        <dl class="mod-facts mb-0">
            <dt>{{ __('Created') }}</dt><dd>{{ $run->createdBy?->name ?? '—' }} · {{ $run->created_at->timezone($timezone)->format('d M Y, g:i a') }}</dd>
            <dt>{{ __('Approved') }}</dt><dd>{{ $run->approvedBy?->name ?? __('Not yet') }}@if ($run->secondApprovedBy) · {{ __('second: :name', ['name' => $run->secondApprovedBy->name]) }}@endif</dd>
            @if ($run->override_reason)
                <dt>{{ __('Override') }}</dt><dd>{{ $run->override_reason }}</dd>
            @endif
            @if ($run->paid_at)
                <dt>{{ __('Completed') }}</dt><dd>{{ $run->paid_at->timezone($timezone)->format('d M Y, g:i a') }}</dd>
            @endif
        </dl>
    </div>

    <x-admin.table :headers="[__('Bureau'), __('Earned'), __('Clawback'), __('Carried in'), __('TDS'), __('Net'), __('Status'), __('UTR'), '']"
                   :caption="__('Payout lines')" :empty="$items->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-bank" :title="__('No lines')" :message="__('This run has no bureau lines.')" />
        </x-slot:emptyState>

        @foreach ($items as $item)
            @php($code = $item->broker?->code)
            <tr wire:key="line-{{ $code }}">
                <td>
                    <a href="{{ route('admin.brokers.show', ['broker' => $code]) }}" wire:navigate><strong>{{ $code }}</strong></a>
                    <div class="small">{{ $item->broker?->displayName() }}</div>
                    @if ($item->status === \App\Enums\PayoutItemStatus::Pending && ! $item->broker?->isPayable())
                        <x-ui.badge variant="danger" icon="fa-exclamation-triangle">{{ __('Can’t be paid now') }}</x-ui.badge>
                    @endif
                </td>
                <td>{{ \App\ValueObjects\Money::paise($item->gross_paise)->formatWithSymbol() }}</td>
                <td>{{ $item->clawback_paise > 0 ? '−'.\App\ValueObjects\Money::paise($item->clawback_paise)->formatWithSymbol() : '—' }}</td>
                <td>{{ $item->carried_in_paise === 0 ? '—' : ($item->carried_in_paise < 0 ? '−' : '').\App\ValueObjects\Money::paise(abs($item->carried_in_paise))->formatWithSymbol() }}</td>
                <td>{{ $item->tds_paise > 0 ? \App\ValueObjects\Money::paise($item->tds_paise)->formatWithSymbol() : '—' }}</td>
                <td>
                    <strong>{{ $item->net()->formatWithSymbol() }}</strong>
                    @if ($item->status === \App\Enums\PayoutItemStatus::CarriedForward)
                        <div class="small text-muted-brand">{{ __('Rolls forward: :amount', ['amount' => ($item->carried_forward_paise < 0 ? '−' : '').\App\ValueObjects\Money::paise(abs($item->carried_forward_paise))->formatWithSymbol()]) }}</div>
                    @endif
                </td>
                <td><x-ui.badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-ui.badge></td>
                <td>{{ $item->utr ?? '—' }}</td>
                <td class="text-end">
                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                        @if ($item->status === \App\Enums\PayoutItemStatus::Pending)
                            @if ($run->status === \App\Enums\PayoutRunStatus::Approved)
                                <x-ui.button size="sm" type="button" icon="fa-check" wire:click="openLine('{{ $code }}', 'paid')" dusk="payout-paid-{{ $code }}">{{ __('Mark paid') }}</x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="outline" type="button" icon="fa-share" wire:click="openLine('{{ $code }}', 'roll')">{{ __('Roll forward') }}</x-ui.button>
                        @elseif ($item->status === \App\Enums\PayoutItemStatus::Paid)
                            <x-ui.button size="sm" variant="outline" icon="fa-file-pdf-o"
                                         :href="route('admin.brokers.payouts.statement', ['run' => $run->code, 'broker' => $code])">{{ __('Statement') }}</x-ui.button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    <x-ui.modal name="payout-paid" :title="__('Mark :code paid', ['code' => $line])">
        <p>{{ __('Enter the bank transfer reference (UTR). The bureau owner is told and can download the statement.') }}</p>
        <x-ui.input :label="__('UTR / bank reference')" name="utr" id="payout-utr" wire:model="utr" maxlength="30" autocomplete="off" required />
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" wire:click="markPaid" loading="markPaid" dusk="payout-paid-confirm">{{ __('Mark paid') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-admin.confirm-modal name="payout-roll" :title="__('Roll :code forward', ['code' => $line])" action="rollForward" :confirmLabel="__('Roll forward')" :danger="false">
        <p>{{ __('Nothing is paid to this bureau in this run; its whole balance moves to the next run.') }}</p>
    </x-admin.confirm-modal>

    @if ($run->status === \App\Enums\PayoutRunStatus::Draft)
        <x-admin.confirm-modal name="payout-discard" :title="__('Discard :code', ['code' => $run->code])" action="discard" :confirmLabel="__('Discard draft')">
            <p>{{ __('The draft and its lines are deleted; the commissions go back to be paid in a later run.') }}</p>
        </x-admin.confirm-modal>
    @endif
</div>
