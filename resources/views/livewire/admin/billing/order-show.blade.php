{{-- A06 order detail: amounts, timeline, payments, refunds, raw webhooks and the order's actions. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ $order->code }}</h1>
            <p>
                <x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Paid ? 'success' : 'muted'">{{ $order->status->label() }}</x-ui.badge>
                @if ($order->failure_reason)
                    <x-ui.badge variant="danger">{{ $order->failure_reason }}</x-ui.badge>
                @endif
            </p>
        </div>
        @if ($order->status !== \App\Enums\OrderStatus::Paid)
            <div class="d-flex flex-wrap gap-2">
                @can('billing.refund')
                    <x-ui.button variant="outline" type="button" icon="fa-refresh" wire:click="repoll" loading="repoll" dusk="order-repoll">{{ __('Re-poll gateway') }}</x-ui.button>
                    <x-ui.button variant="outline" type="button" x-on:click="$dispatch('open-modal', { name: 'order-fail' })" dusk="order-fail-open">{{ __('Mark failed') }}</x-ui.button>
                @endcan
                @if ($canForce)
                    <x-ui.button variant="danger" type="button" x-on:click="$dispatch('open-modal', { name: 'order-force' })" dusk="order-force-open">{{ __('Force-activate') }}</x-ui.button>
                @endif
            </div>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <div class="ui-card mb-4">
                <h2 class="h6">{{ __('Summary') }}</h2>
                <dl class="mb-0">
                    <dt>{{ __('Member') }}</dt><dd>{{ $order->profile?->code }}</dd>
                    <dt>{{ __('Plan') }}</dt><dd>{{ $order->plan?->name }}, {{ trans_choice(':count month|:count months', $order->duration_months) }}</dd>
                    @if ($order->coupon)
                        <dt>{{ __('Coupon') }}</dt><dd>{{ $order->coupon->code }}, −₹{{ \App\ValueObjects\Money::paise($order->discount_paise)->format() }}</dd>
                    @endif
                    <dt>{{ __('Taxable') }}</dt><dd>₹{{ \App\ValueObjects\Money::paise($order->taxable_paise)->format() }}</dd>
                    <dt>{{ __('GST') }}</dt><dd>₹{{ \App\ValueObjects\Money::paise($order->gst_paise)->format() }}</dd>
                    <dt>{{ __('Total') }}</dt><dd><strong>₹{{ $order->total()->format() }}</strong></dd>
                    @if ($order->invoice)
                        <dt>{{ __('Invoice') }}</dt><dd>{{ $order->invoice->number }}</dd>
                    @endif
                </dl>
            </div>

            <div class="ui-card">
                <h2 class="h6">{{ __('Timeline (IST)') }}</h2>
                <ol class="mb-0 ps-3">
                    @foreach ($timeline as $event)
                        <li>{{ \Illuminate\Support\Carbon::instance($event['at'])->timezone($timezone)->format('d M Y, g:i a') }}: {{ $event['text'] }}</li>
                    @endforeach
                </ol>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <x-admin.table class="mb-4" :headers="[__('Payment'), __('Amount'), __('Status'), __('Refundable'), '']" :caption="__('Payments')" :empty="$order->payments->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-credit-card" :title="__('No payments yet')" :message="__('Nothing has reached the gateway for this order.')" />
                </x-slot:emptyState>
                @foreach ($order->payments as $payment)
                    <tr wire:key="pay-{{ $payment->id }}">
                        <td>{{ $payment->gateway_payment_id }}{{ $payment->id === $order->paid_payment_id ? ' ('.__('activated the order').')' : '' }}</td>
                        <td>₹{{ \App\ValueObjects\Money::paise($payment->amount_paise)->format() }}</td>
                        <td>{{ $payment->status->value }}</td>
                        <td>₹{{ \App\ValueObjects\Money::paise($refundable[$payment->id] ?? 0)->format() }}</td>
                        <td class="text-nowrap">
                            @can('billing.refund')
                                @if (($refundable[$payment->id] ?? 0) > 0)
                                    <x-ui.button size="sm" variant="outline" type="button" wire:click="openRefund('{{ $payment->gateway_payment_id }}')" dusk="refund-open">{{ __('Refund') }}</x-ui.button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            @if ($order->refunds->isNotEmpty())
                <x-admin.table class="mb-4" :headers="[__('Refund'), __('Amount'), __('Category'), __('Status'), __('Requested by'), __('Decided by'), __('Credit note')]" :caption="__('Refunds')">
                    @foreach ($order->refunds as $refund)
                        <tr wire:key="rfd-{{ $refund->code }}">
                            <td>{{ $refund->code }}</td>
                            <td>₹{{ $refund->amount()->format() }}</td>
                            <td>{{ $refund->category->label() }}</td>
                            <td><x-ui.badge :variant="$refund->status->badge()">{{ $refund->status->label() }}</x-ui.badge></td>
                            <td>{{ $refund->requestedBy->name }}</td>
                            <td>{{ $refund->decidedBy?->name ?? '—' }}</td>
                            <td>
                                @if ($refund->creditNote)
                                    <a href="{{ route('admin.billing.credit-notes.show', ['number' => $refund->creditNote->number]) }}">{{ $refund->creditNote->number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif

            <div class="ui-card">
                <h2 class="h6">{{ __('Webhooks received') }}</h2>
                @forelse ($webhooks as $webhook)
                    <details class="mb-2" wire:key="hook-{{ $webhook->id }}">
                        <summary>{{ $webhook->event }}: {{ $webhook->outcome ?? __('not processed') }}, {{ $webhook->created_at?->timezone($timezone)->format('d M Y, g:i a') }}</summary>
                        <pre class="admin-pre"><code>{{ json_encode(json_decode($webhook->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </details>
                @empty
                    <p class="text-muted mb-0">{{ __('None for this order.') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    @can('billing.refund')
        <x-admin.confirm-modal name="refund-request" :title="__('Request a refund')" action="requestRefund" :confirmLabel="__('Request refund')">
            <p>{{ __('Another admin must approve this before any money goes back.') }}</p>
            <div class="row g-3">
                <div class="col-12 col-md-6"><x-ui.input :label="__('Amount, ₹')" name="refundAmount" id="rf-amount" inputmode="decimal" wire:model="refundAmount" required /></div>
                <div class="col-12 col-md-6"><x-ui.select :label="__('Category')" name="refundCategory" id="rf-category" wire:model="refundCategory" :options="$categories" /></div>
            </div>
        </x-admin.confirm-modal>
        <x-admin.confirm-modal name="order-fail" :title="__('Mark :code failed', ['code' => $order->code])" action="markFailed" :confirmLabel="__('Mark failed')">
            <p>{{ __('Only an order with no captured payment can be marked failed.') }}</p>
        </x-admin.confirm-modal>
    @endcan
    @if ($canForce)
        <x-admin.confirm-modal name="order-force" :title="__('Force-activate :code', ['code' => $order->code])" action="forceActivate" :confirmLabel="__('Force-activate')">
            <p>{{ __('This activates the plan with the captured payment, which must cover the order total: a short payment is refunded instead, and the member can pay again or get a complimentary grant. Super admins only.') }}</p>
        </x-admin.confirm-modal>
    @endif
</div>
