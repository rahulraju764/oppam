{{--
    /billing/success/{order} (F04): waiting → paid | failed | closed. While waiting the card polls
    every 2 s (the live `.entitlements.changed` re-renders sooner); the status line is a polite
    live region so screen readers hear the change.
--}}
<div>
    <section class="checkout-section">
        <div class="container is-readable">
            <div class="row justify-content-center">
                <div class="col-lg-7 col-12">
                    <div class="checkout-card billing-status" @if ($polling) wire:poll.2s="check" @endif>
                        @switch($state)
                            @case('paid')
                                <i class="fa fa-check-circle" aria-hidden="true"></i>
                                <h1>{{ __('Payment received') }}</h1>
                                <p role="status">
                                    {{ __('Your :plan membership is active until :date.', [
                                        'plan' => $record->plan->name,
                                        'date' => $record->subscription?->ends_at->timezone($displayZone)->format('j M Y'),
                                    ]) }}
                                </p>
                                <dl class="checkout-lines">
                                    <div><dt>{{ __('Order') }}</dt><dd>{{ $record->code }}</dd></div>
                                    <div><dt>{{ __('Amount paid') }}</dt><dd>&#8377;{{ $record->total()->format() }}</dd></div>
                                    @if ($record->invoice)
                                        <div><dt>{{ __('Invoice') }}</dt><dd>{{ $record->invoice->number }}</dd></div>
                                    @endif
                                </dl>
                                <div class="billing-status-actions">
                                    <x-ui.button :href="route('member.messages')" icon="fa-comments" wire:navigate>{{ __('Go to messages') }}</x-ui.button>
                                    @if ($record->invoice)
                                        <x-ui.button variant="outline" icon="fa-download"
                                                     :href="route('member.billing.invoice', ['invoice' => $record->invoice->number])">{{ __('Download invoice') }}</x-ui.button>
                                    @endif
                                </div>
                                @break

                            @case('failed')
                                <i class="fa fa-times-circle" aria-hidden="true"></i>
                                <h1>{{ __('Payment didn’t go through') }}</h1>
                                <p role="status">{{ __('No money was taken for order :code. You can try again, or choose another payment method.', ['code' => $record->code]) }}</p>
                                <div class="billing-status-actions">
                                    <x-ui.button :href="route('member.checkout', ['plan' => strtolower($record->plan->code->value), 'duration' => $record->duration_months])" wire:navigate>{{ __('Try again') }}</x-ui.button>
                                    <x-ui.button variant="outline" :href="route('plans')" wire:navigate>{{ __('See plans') }}</x-ui.button>
                                </div>
                                @break

                            @case('closed')
                                <i class="fa fa-times-circle" aria-hidden="true"></i>
                                <h1>{{ __('This order has closed') }}</h1>
                                <p role="status">{{ __('Order :code was not paid. If money left your account, it will be matched to this order automatically — or contact us with the order number.', ['code' => $record->code]) }}</p>
                                <div class="billing-status-actions">
                                    <x-ui.button :href="route('plans')" wire:navigate>{{ __('See plans') }}</x-ui.button>
                                </div>
                                @break

                            @default
                                <div class="spinner-border text-danger mb-3" aria-hidden="true"></div>
                                <h1>{{ __('Confirming your payment…') }}</h1>
                                <p role="status">
                                    @if ($polling)
                                        {{ __('This usually takes a few seconds. Please keep this page open.') }}
                                    @else
                                        {{ __('This is taking longer than usual. Your payment is safe — the plan activates as soon as the bank confirms it.') }}
                                    @endif
                                </p>
                                <p class="checkout-note text-muted-brand">{{ __('Order :code · ₹:amount', ['code' => $record->code, 'amount' => $record->total()->format()]) }}</p>
                                @unless ($polling)
                                    <div class="billing-status-actions">
                                        <x-ui.button type="button" wire:click="retry" loading="retry">{{ __('Check again') }}</x-ui.button>
                                    </div>
                                @endunless
                        @endswitch
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
