{{-- A06 refunds: a second admin decides every one; the requester may withdraw while it waits. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Refunds') }}</h1>
            <p>{{ __('Every refund needs a second admin. Request one from the order’s page.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Status')" name="status" id="rfd-status" wire:model.live="status" :options="$statuses" :placeholder="__('Any')" />
            </div>
        </div>
    </div>

    <x-admin.table :headers="[__('Refund'), __('Order'), __('Member'), __('Amount'), __('Category'), __('Reason'), __('Status'), __('Requested by'), __('Actions')]" :caption="__('Refunds')" :empty="$refunds->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-undo" :title="__('Nothing here')" :message="__('No refund has this status.')" />
        </x-slot:emptyState>

        @foreach ($refunds as $refund)
            <tr wire:key="refund-{{ $refund->code }}">
                <td><strong>{{ $refund->code }}</strong></td>
                <td><a href="{{ route('admin.billing.orders.show', ['order' => $refund->order->code]) }}" wire:navigate>{{ $refund->order->code }}</a></td>
                <td>{{ $refund->profile?->code }}</td>
                <td>₹{{ $refund->amount()->format() }}{{ $refund->ends_plan ? ' ('.__('ends the plan').')' : '' }}</td>
                <td>{{ $refund->category->label() }}</td>
                <td>{{ $refund->reason }}</td>
                <td><x-ui.badge :variant="$refund->status->badge()">{{ $refund->status->label() }}</x-ui.badge></td>
                <td>{{ $refund->requestedBy->name }}, {{ $refund->created_at->timezone($timezone)->format('d M, g:i a') }}</td>
                <td class="text-nowrap">
                    @can('billing.refund')
                        @if ($refund->status === \App\Enums\RefundStatus::Requested)
                            @if ($refund->requested_by_id === $adminId)
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="withdraw('{{ $refund->code }}')" loading="withdraw">{{ __('Withdraw') }}</x-ui.button>
                            @else
                                <x-ui.button size="sm" type="button" wire:click="confirm('{{ $refund->code }}', 'refund-approve')" dusk="refund-approve-open">{{ __('Approve') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" type="button" wire:click="confirm('{{ $refund->code }}', 'refund-reject')">{{ __('Reject') }}</x-ui.button>
                            @endif
                        @endif
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $refunds->links() }}

    <x-ui.modal name="refund-approve" :title="__('Approve :code', ['code' => $selected])">
        <p>{{ __('This sends the money back through the gateway. It can’t be undone.') }}</p>
        <x-ui.textarea :label="__('Note (optional)')" name="approve-note" id="rfd-approve-note" wire:model="note" error="note" rows="2" />
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" type="button" wire:click="approve" loading="approve" dusk="refund-approve">{{ __('Approve and refund') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-admin.confirm-modal name="refund-reject" :title="__('Reject :code', ['code' => $selected])" action="reject" reasonModel="note" :confirmLabel="__('Reject')" />
</div>
