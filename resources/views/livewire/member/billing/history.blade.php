{{--
    /billing (M10, new page — styled with the checkout cards): the plan in force + plans already
    bought, the §7.3 usage meters with their reset dates, the plan's features, and the member's
    orders with invoice downloads (10 a page). Everything shown comes from MembershipSummary /
    the member's own orders.
--}}
@php($date = fn ($at) => $at?->copy()->timezone($displayZone)->format('j M Y'))

<div>
    <section class="checkout-section">
        <div class="container is-readable">
            <h1 class="billing-title">{{ __('Membership & billing') }}</h1>

            <div class="row g-4">
                {{-- CURRENT PLAN --}}
                <div class="col-lg-5 col-12">
                    <div class="checkout-card">
                        <h2>{{ __('Your plan') }}</h2>

                        <div class="checkout-plan">
                            <div>
                                <h3>{{ __(':plan Membership', ['plan' => $summary->planName]) }}</h3>
                                <p class="text-muted-brand">
                                    @if ($summary->isFree())
                                        {{ __('Free — search, view profiles, daily matches and one reply in each conversation.') }}
                                    @elseif ($summary->source === \App\Enums\SubscriptionSource::Complimentary)
                                        {{ __('Complimentary, until :date', ['date' => $date($summary->endsAt)]) }}
                                    @else
                                        {{ __('Active until :date', ['date' => $date($summary->endsAt)]) }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        @foreach ($summary->queued as $next)
                            <p class="checkout-note text-muted-brand">
                                <i class="fa fa-calendar-plus-o" aria-hidden="true"></i>
                                {{ __('Then :plan from :from until :until.', ['plan' => $next['plan'], 'from' => $date($next['starts_at']), 'until' => $date($next['ends_at'])]) }}
                            </p>
                        @endforeach

                        <ul class="checkout-features">
                            @foreach ($summary->features as $feature)
                                <li @class(['is-off' => ! $feature['included']])>
                                    <i @class(['fa', 'fa-check' => $feature['included'], 'fa-minus' => ! $feature['included']]) aria-hidden="true"></i>
                                    {{ $feature['label'] }}
                                    <span class="visually-hidden">{{ $feature['included'] ? __('Included') : __('Not included') }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <x-ui.button :href="route('plans')" icon="fa-diamond" wire:navigate>
                            {{ $summary->isFree() ? __('Upgrade membership') : __('Renew or upgrade') }}
                        </x-ui.button>
                    </div>
                </div>

                {{-- USAGE METERS --}}
                <div class="col-lg-7 col-12">
                    <div class="checkout-card">
                        <h2>{{ __('Your usage') }}</h2>

                        @foreach ($summary->meters as $meter)
                            <div class="usage-meter">
                                <div class="usage-meter__head">
                                    <span class="usage-meter__label">{{ $meter->label }}</span>
                                    <span class="usage-meter__value">
                                        @if ($meter->limit === null)
                                            {{ __(':used used · Unlimited', ['used' => number_format($meter->used)]) }}
                                        @elseif ($meter->limit === 0)
                                            {{ __('Not in your plan') }}
                                        @else
                                            {{ __(':used of :limit used · :left left', ['used' => number_format($meter->used), 'limit' => number_format($meter->limit), 'left' => number_format((int) $meter->remaining())]) }}
                                        @endif
                                    </span>
                                </div>
                                @if ($meter->limit !== null && $meter->limit > 0)
                                    <div class="usage-meter__bar" role="progressbar" aria-label="{{ $meter->label }}"
                                         aria-valuemin="0" aria-valuemax="{{ $meter->limit }}" aria-valuenow="{{ min($meter->used, $meter->limit) }}">
                                        <span style="--progress: {{ $meter->percent() }}%"></span>
                                    </div>
                                @endif
                                <p class="usage-meter__reset">
                                    {{ $meter->resetsAt ? __('Resets :date', ['date' => $meter->resetsAt->timezone($displayZone)->format('j M Y, g:i A')]) : __('A running total — removing one frees a place.') }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- ORDERS --}}
                <div class="col-12">
                    <div class="checkout-card">
                        <h2>{{ __('Payments & invoices') }}</h2>

                        @if ($orders->isEmpty())
                            <x-ui.empty-state icon="fa-file-text-o" :title="__('No payments yet')"
                                              :message="__('Your orders and GST invoices will appear here after you buy a membership.')" />
                        @else
                            <div class="table-responsive billing-orders">
                                <table class="table">
                                    <caption class="visually-hidden">{{ __('Your orders') }}</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('Date') }}</th>
                                            <th scope="col">{{ __('Order') }}</th>
                                            <th scope="col">{{ __('Plan') }}</th>
                                            <th scope="col" class="text-end">{{ __('Amount') }}</th>
                                            <th scope="col">{{ __('Status') }}</th>
                                            <th scope="col">{{ __('Invoice') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($orders as $order)
                                            <tr wire:key="order-{{ $order->code }}">
                                                <td>{{ $date($order->created_at) }}</td>
                                                <td>{{ $order->code }}</td>
                                                <td>{{ trans_choice(':plan · :count month|:plan · :count months', $order->duration_months, ['plan' => $order->plan->name, 'count' => $order->duration_months]) }}</td>
                                                <td class="text-end">&#8377;{{ $order->total()->format() }}</td>
                                                <td>
                                                    <x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Paid ? 'success' : 'muted'">{{ $order->status->label() }}</x-ui.badge>
                                                </td>
                                                <td>
                                                    @if ($order->invoice)
                                                        <a href="{{ route('member.billing.invoice', ['invoice' => $order->invoice->number]) }}">
                                                            <i class="fa fa-download" aria-hidden="true"></i>
                                                            {{ $order->invoice->number }}<span class="visually-hidden"> {{ __('(PDF)') }}</span>
                                                        </a>
                                                    @else
                                                        <span class="text-muted-brand">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                    <x-ui.pagination :paginator="$orders" :label="__('Orders pages')" />
                </div>
            </div>
        </div>
    </section>
</div>
