{{--
    /broker/earnings (§11A B.10, B.11) — Owner only. Centre column + right rail of the 3/6/3 row opened
    by layouts.broker. Members by profile code only (R-M13-7).
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Earnings') }}</h1>
                <p>{{ __('Your commission is :rate% of the plan price before GST, on the first plan bought by each family you referred or manage.', ['rate' => $rate]) }}</p>
            </div>

            @if ($held)
                <x-ui.alert type="warning">{{ __('Your payouts are on hold while our team reviews your account. You keep earning; contact support for details.') }}</x-ui.alert>
            @endif

            <dl class="broker-widget__stats">
                <div><dt>{{ __('Earned, not yet paid') }}</dt><dd>{{ $unpaid->formatWithSymbol() }}</dd></div>
                <div><dt>{{ __('Paid to you') }}</dt><dd>{{ $paid->formatWithSymbol() }}</dd></div>
                <div><dt>{{ __('Waiting for a first plan') }}</dt><dd>{{ trans_choice(':count family|:count families', $pendingCount) }}</dd></div>
            </dl>
        </div>

        <div class="dashboard-content mb-4">
            <h2 class="h5">{{ __('Payouts') }}</h2>
            @if ($payouts->isEmpty())
                <x-ui.empty-state icon="fa-bank" :title="__('No payouts yet')" :message="__('Payouts are made monthly once your balance reaches :min.', ['min' => $minimum->formatWithSymbol()])" />
            @else
                <div class="table-responsive">
                    <table class="table broker-table align-middle mb-0">
                        <caption class="visually-hidden">{{ __('Your payouts') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Period') }}</th>
                                <th scope="col" class="text-end">{{ __('Amount') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col"><span class="visually-hidden">{{ __('Statement') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payouts as $payout)
                                <tr wire:key="payout-{{ $payout->run?->code }}">
                                    <td>{{ $payout->run?->label() }}</td>
                                    <td class="text-end">{{ $payout->status === \App\Enums\PayoutItemStatus::Paid ? $payout->net()->formatWithSymbol() : '—' }}</td>
                                    <td>
                                        @if ($payout->status === \App\Enums\PayoutItemStatus::Paid)
                                            <x-ui.badge variant="success">{{ __('Paid :date', ['date' => $payout->paid_at?->timezone($timezone)->format('j M Y')]) }}</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="muted">{{ __('Moved to next payout') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($payout->status === \App\Enums\PayoutItemStatus::Paid)
                                            <a href="{{ route('broker.earnings.statement', ['run' => $payout->run?->code]) }}">
                                                <i class="fa fa-file-pdf-o" aria-hidden="true"></i> {{ __('Statement') }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="dashboard-content" wire:loading.class="is-loading" wire:target="gotoPage, nextPage, previousPage">
            <h2 class="h5">{{ __('Commission by profile') }}</h2>
            @if ($ledger->isEmpty())
                <x-ui.empty-state icon="fa-inr" :title="__('No commission yet')"
                    :message="__('When a family you referred or manage buys its first plan, the commission appears here.')" />
            @else
                <div class="table-responsive">
                    <table class="table broker-table align-middle mb-0">
                        <caption class="visually-hidden">{{ __('Commission by profile') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Profile') }}</th>
                                <th scope="col">{{ __('Earned') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="text-end">{{ __('Commission') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger as $row)
                                <tr wire:key="ledger-{{ $row->profile?->code }}">
                                    <td>{{ $row->profile?->code ?? '—' }}<div class="small text-muted-brand">{{ $row->source->label() }}</div></td>
                                    <td>{{ $row->earned_at?->timezone($timezone)->format('j M Y') ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :variant="$row->status->badgeVariant()">
                                            {{ match ($row->status) {
                                                \App\Enums\ReferralStatus::Earned => __('Next payout'),
                                                \App\Enums\ReferralStatus::Paid => __('Paid'),
                                                default => __('Refunded — reversed'),
                                            } }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="text-end">{{ $row->commission()->formatWithSymbol() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-ui.pagination :paginator="$ledger" :label="__('Commission pages')" />
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('How payouts work') }}</h2>
            <ul class="broker-help__steps">
                <li>{{ __('Commission is earned when the family’s profile is approved and they buy their first plan.') }}</li>
                <li>{{ __('Payouts go to your verified bank account once a month.') }}</li>
                <li>{{ __('Balances under :min move to the next month.', ['min' => $minimum->formatWithSymbol()]) }}</li>
                <li>{{ __('If a plan is refunded, its commission is taken back from your next payout.') }}</li>
            </ul>
            <p class="text-muted-brand small mb-0">{{ __('Only the bureau owner can see this page.') }}</p>
        </div>
    </div>
</div>
