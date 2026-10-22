{{--
    Checkout (template checkout.php, M10 / F04). Everything priced here comes from the server's
    quote (plan KEY + duration + a coupon the server accepted). No card fields: Razorpay Checkout
    collects payment in its own window (Alpine `razorpayCheckout`), or — PAYMENT_DRIVER=fake — the
    "Complete test payment" buttons simulate it. The demo banner stays whenever no payment can be
    taken (template rule), and in test mode.
--}}
@php
    use App\Enums\PlanChange;
    $from = $quote->startsAt->timezone($displayZone)->format('j M Y');
    $until = $quote->endsAt->timezone($displayZone)->format('j M Y');
    $currentUntil = $quote->currentEndsAt?->timezone($displayZone)->format('j M Y');
    $blocked = ! $available || $support || $quote->change === PlanChange::Downgrade;
@endphp

<div x-data="razorpayCheckout(@js(config('oppam.payments.checkout_script')), @js([
         'loadFailed' => __('The payment window could not be opened. Please check your connection and try again.'),
         'failed' => __('The payment did not go through. You can try again, or choose another payment method.'),
     ]))"
     x-on:razorpay-open.window="open($event.detail.options, $event.detail.successUrl)">
    <section class="checkout-section">
        <div class="container is-readable">

            <h1 class="visually-hidden">{{ __('Checkout') }}</h1>

            @if (! $available)
                <div class="demo-banner" role="alert">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <div>
                        <strong>{{ __('Payments are not enabled yet — no payment is taken.') }}</strong>
                        <p>{{ __('Nothing is charged and no order is created on this page. To buy a membership,') }}
                            <a href="{{ route('contact') }}" wire:navigate>{{ __('contact us') }}</a>.</p>
                    </div>
                </div>
            @elseif ($testMode)
                <div class="demo-banner" role="alert">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <div>
                        <strong>{{ __('Test mode — no real payment is taken.') }}</strong>
                        <p>{{ __('This build uses a simulated payment gateway. “Complete test payment” activates the plan without any money changing hands.') }}</p>
                    </div>
                </div>
            @endif

            @if ($support)
                <x-ui.alert type="warning">{{ __('Not available while our team is viewing this account for support.') }}</x-ui.alert>
            @endif

            @if ($refusal)
                <x-ui.alert type="danger">{{ $refusal }}</x-ui.alert>
            @endif

            @switch($quote->change)
                @case(PlanChange::Extend)
                    <x-ui.alert type="info">{{ __('You are on :plan until :date. These months are added after that — your plan will run until :until.', ['plan' => $quote->currentPlanName, 'date' => $currentUntil, 'until' => $until]) }}</x-ui.alert>
                    @break
                @case(PlanChange::Upgrade)
                    <x-ui.alert type="info">{{ __('Upgrading ends your :plan plan (until :date) as soon as this payment goes through. Unused time is not refunded.', ['plan' => $quote->currentPlanName, 'date' => $currentUntil]) }}</x-ui.alert>
                    @break
                @case(PlanChange::Downgrade)
                    <x-ui.alert type="warning">{{ __('You are on :plan until :date. You can choose a lower plan after it ends.', ['plan' => $quote->currentPlanName, 'date' => $currentUntil]) }}</x-ui.alert>
                    @break
            @endswitch

            <div class="row g-4">

                {{-- ORDER SUMMARY --}}
                <div class="col-lg-7 col-12">
                    <div class="checkout-card">
                        <h2>{{ __('Your plan') }}</h2>

                        <div class="checkout-plan">
                            <div>
                                <h3>{{ __(':plan Membership', ['plan' => $quote->planName]) }}</h3>
                                <p class="text-muted-brand">
                                    {{ trans_choice(':count month, billed once|:count months, billed once', $quote->durationMonths, ['count' => $quote->durationMonths]) }}
                                    · {{ $quote->change === PlanChange::Extend ? __(':from – :until', ['from' => $from, 'until' => $until]) : __('until :until', ['until' => $until]) }}
                                </p>
                            </div>
                            <p class="checkout-plan-price">&#8377;{{ $quote->price->subtotal()->format() }}</p>
                        </div>

                        @if (count($durations) > 1)
                            <div class="pill-switch checkout-durations" role="group" aria-label="{{ __('Duration') }}">
                                @foreach ($durations as $months)
                                    <button type="button" class="pill-option" wire:click="$set('duration', {{ $months }})"
                                            aria-pressed="{{ $months === $quote->durationMonths ? 'true' : 'false' }}"
                                            wire:loading.attr="disabled" wire:target="duration">
                                        {{ trans_choice(':count month|:count months', $months, ['count' => $months]) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <ul class="checkout-features">
                            @foreach ($features as $feature)
                                <li><i class="fa fa-check" aria-hidden="true"></i> {{ $feature }}</li>
                            @endforeach
                        </ul>

                        <p class="checkout-change">
                            <a href="{{ route('plans') }}" wire:navigate>{{ __('Choose a different plan') }}</a>
                        </p>
                    </div>
                </div>

                {{-- TOTALS --}}
                <div class="col-lg-5 col-12">
                    <div class="checkout-card checkout-total">
                        <h2>{{ __('Total') }}</h2>

                        <div class="checkout-coupon">
                            @if ($appliedCoupon)
                                <div class="checkout-coupon-applied" role="status">
                                    <span><i class="fa fa-tag" aria-hidden="true"></i> {{ __('Code :code applied', ['code' => $appliedCoupon]) }}</span>
                                    <x-ui.button variant="link" size="sm" type="button" wire:click="removeCoupon" loading="removeCoupon">{{ __('Remove') }}</x-ui.button>
                                </div>
                            @else
                                <x-ui.input :label="__('Coupon code')" name="coupon" wire:model.live.debounce.400ms="coupon"
                                            autocomplete="off" maxlength="30" autocapitalize="characters" spellcheck="false" />
                                <p class="visually-hidden" wire:loading wire:target="coupon" role="status">{{ __('Checking the code…') }}</p>
                            @endif
                        </div>

                        <dl class="checkout-lines" wire:loading.class="opacity-50" wire:target="duration, coupon, removeCoupon">
                            <div>
                                <dt>{{ __('Subtotal') }}</dt>
                                <dd>&#8377;{{ $quote->price->subtotal()->format() }}</dd>
                            </div>
                            @if ($quote->price->discountPaise > 0)
                                <div class="checkout-line-discount">
                                    <dt>{{ __('Coupon (:code)', ['code' => $quote->couponCode]) }}</dt>
                                    <dd>&minus;&#8377;{{ $quote->price->discount()->format() }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>{{ __('GST (:rate%)', ['rate' => $quote->price->gstRateLabel()]) }}</dt>
                                <dd>&#8377;{{ $quote->price->gst()->format() }}</dd>
                            </div>
                            <div class="checkout-line-total">
                                <dt>{{ __('Amount payable') }}</dt>
                                <dd>&#8377;{{ $quote->price->total()->format() }}</dd>
                            </div>
                        </dl>

                        @if ($order && $testMode)
                            <div class="checkout-test">
                                <p class="checkout-note text-muted-brand">{{ __('Order :code — test payment of ₹:amount.', ['code' => $order->code, 'amount' => $order->total()->format()]) }}</p>
                                <button type="button" class="btn login-btn" wire:click="completeTestPayment(true)" dusk="checkout-test-pay"
                                        wire:loading.attr="disabled" wire:target="completeTestPayment">
                                    <span wire:loading.remove wire:target="completeTestPayment">{{ __('Complete test payment') }}</span>
                                    <span wire:loading wire:target="completeTestPayment">{{ __('Processing…') }}</span>
                                </button>
                                <x-ui.button variant="link" size="sm" type="button" wire:click="completeTestPayment(false)">{{ __('Simulate a failed payment') }}</x-ui.button>
                            </div>
                        @else
                            <button type="button" class="btn login-btn" wire:click="pay" dusk="checkout-pay"
                                    @disabled($blocked) x-bind:disabled="opening || @js($blocked)"
                                    wire:loading.attr="disabled" wire:target="pay">
                                <span wire:loading.remove wire:target="pay">{{ __('Continue to payment') }}</span>
                                <span wire:loading wire:target="pay">{{ __('Preparing payment…') }}</span>
                            </button>
                            <p class="checkout-note text-danger" role="alert" x-show="error" x-text="error" x-cloak></p>
                        @endif

                        <p class="checkout-note text-muted-brand">
                            {{ __('You pay securely on Razorpay (UPI, cards, netbanking, wallets). We never see your card details. A GST invoice is issued once the payment is confirmed.') }}
                        </p>
                        <p class="checkout-note text-muted-brand">
                            {{ __('By subscribing you accept our') }}
                            <a href="{{ route('terms') }}" wire:navigate>{{ __('Terms of Use') }}</a>.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>
</div>
