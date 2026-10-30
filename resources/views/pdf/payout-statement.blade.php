{{--
    Bureau payout statement (§11A B.10) rendered by dompdf (DompdfPayoutStatementRenderer). A PDF, not
    a page: its own small stylesheet (dompdf can't read the app's CSS / tokens). Members by profile
    code only (R-M13-7); the bank account by its last 4 digits.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payout statement :run · :code', ['run' => $run->code, 'code' => $broker->code]) }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #2d2d2d; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #b51b3b; }
        h2 { font-size: 13px; margin: 16px 0 6px; }
        .muted { color: #5e6f80; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; width: 50%; padding: 4px 0; }
        .lines th, .lines td { border: 1px solid #d9d9d9; padding: 5px 7px; }
        .lines th { background: #f4f4f4; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; }
        .footer { margin-top: 24px; font-size: 10px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>{{ __('Payout statement') }}</h1>
                <div class="muted">{{ config('oppam.site.name') }}</div>
            </td>
            <td class="num">
                <strong>{{ $run->code }}</strong><br>
                {{ __('Period: :period', ['period' => $run->label()]) }}<br>
                {{ __('Paid on: :date', ['date' => $paidOn ?? '—']) }}<br>
                {{ __('UTR: :utr', ['utr' => $item->utr ?? '—']) }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>{{ $broker->displayName() }}</strong> ({{ $broker->code }})<br>
                {{ $broker->name }}@if ($broker->district), {{ $broker->district->label }}@endif
            </td>
            <td class="num">
                @if ($kyc)
                    {{ $kyc->account_name }}<br>
                    {{ __('Account ending :last4 · IFSC :ifsc', ['last4' => $kyc->bank_last4, 'ifsc' => $kyc->ifsc]) }}<br>
                    {{ __('PAN ending :last4', ['last4' => $kyc->pan_last4]) }}
                @endif
            </td>
        </tr>
    </table>

    <h2>{{ __('Commission paid') }}</h2>
    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Profile') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Order') }}</th>
                <th class="num">{{ __('Base (₹, before GST)') }}</th>
                <th class="num">{{ __('Rate') }}</th>
                <th class="num">{{ __('Commission (₹)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($item->referrals as $row)
                <tr>
                    <td>{{ $row->profile?->code ?? '—' }}</td>
                    <td>{{ $row->source->label() }}@if ($row->status === \App\Enums\ReferralStatus::Void) · {{ __('refunded later') }}@endif</td>
                    <td>{{ $row->order?->code ?? '—' }}</td>
                    <td class="num">{{ \App\ValueObjects\Money::paise((int) $row->base_paise)->format() }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format(((int) $row->commission_bps) / 100, 2, '.', ''), '0'), '.') }}%</td>
                    <td class="num">{{ $row->commission()->format() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">{{ __('No new commission in this payout.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($item->clawbacks->isNotEmpty())
        <h2>{{ __('Clawbacks (refunded after an earlier payout)') }}</h2>
        <table class="lines">
            <thead>
                <tr>
                    <th>{{ __('Profile') }}</th>
                    <th>{{ __('Order') }}</th>
                    <th class="num">{{ __('Amount (₹)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($item->clawbacks as $row)
                    <tr>
                        <td>{{ $row->profile?->code ?? '—' }}</td>
                        <td>{{ $row->order?->code ?? '—' }}</td>
                        <td class="num">−{{ $row->commission()->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Summary') }}</h2>
    <table class="lines">
        <tbody>
            <tr><td>{{ __('Commission earned') }}</td><td class="num">{{ $gross->format() }}</td></tr>
            <tr><td>{{ __('Clawbacks') }}</td><td class="num">−{{ $clawback->format() }}</td></tr>
            <tr><td>{{ __('Balance carried from earlier runs') }}</td><td class="num">{{ $carriedIn < 0 ? '−' : '' }}{{ \App\ValueObjects\Money::paise(abs($carriedIn))->format() }}</td></tr>
            <tr><td>{{ __('TDS deducted') }}</td><td class="num">−{{ $tds->format() }}</td></tr>
            <tr class="total"><td>{{ __('Net paid') }}</td><td class="num">₹{{ $net->format() }}</td></tr>
        </tbody>
    </table>

    <p class="footer muted">
        {{ __('Commission is your agreed share of the plan price before GST, on the first paid plan of each profile you referred or manage. A refund reverses it on your next payout. This is a computer-generated statement.') }}
    </p>
</body>
</html>
