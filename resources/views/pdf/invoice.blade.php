{{--
    GST tax invoice (M10, A06) rendered by dompdf (DompdfInvoiceRenderer). A PDF, not a page: it
    carries its own small stylesheet (dompdf can't read the app's CSS / tokens). Everything printed
    comes from the invoice's frozen seller / buyer copy and the order's amounts.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Tax invoice :number', ['number' => $invoice->number]) }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #2d2d2d; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #b51b3b; }
        .muted { color: #5e6f80; }
        .warning { border: 1px solid #b8860b; color: #6b4c00; padding: 6px 8px; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; width: 50%; padding: 8px 0; }
        .lines th, .lines td { border: 1px solid #d9d9d9; padding: 6px 8px; }
        .lines th { background: #f4f4f4; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; }
        .footer { margin-top: 24px; font-size: 10px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>{{ __('Tax invoice') }}</h1>
                <div class="muted">{{ __('Original for recipient') }}</div>
            </td>
            <td class="num">
                <strong>{{ $invoice->number }}</strong><br>
                {{ __('Date: :date', ['date' => $issuedOn]) }}<br>
                {{ __('Financial year: :year', ['year' => $financialYear]) }}<br>
                {{ __('Order: :code', ['code' => $order->code]) }}
            </td>
        </tr>
    </table>

    @if ($invoice->seller['gstin'] === '')
        <div class="warning">{{ __('Seller GSTIN not configured — this document is not a valid GST tax invoice.') }}</div>
    @endif

    <table class="parties">
        <tr>
            <td>
                <strong>{{ __('Supplier') }}</strong><br>
                {{ $invoice->seller['name'] }}<br>
                {!! nl2br(e($invoice->seller['address'])) !!}<br>
                {{ __('GSTIN: :gstin', ['gstin' => $invoice->seller['gstin'] !== '' ? $invoice->seller['gstin'] : '—']) }}<br>
                {{ __('State code: :code', ['code' => $invoice->seller['state_code']]) }}
            </td>
            <td>
                <strong>{{ __('Recipient') }}</strong><br>
                {{ $invoice->buyer['name'] }} ({{ $invoice->buyer['code'] }})<br>
                {{ __('Unregistered (B2C)') }}<br>
                {{ __('Place of supply: :state', ['state' => $invoice->buyer['state'] ?? __('Not on record — supplier’s state')]) }}
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th>{{ __('SAC') }}</th>
                <th class="num">{{ __('Amount (₹)') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ trans_choice(':plan membership — :count month|:plan membership — :count months', $order->duration_months, ['plan' => $order->plan->name, 'count' => $order->duration_months]) }}</td>
                <td>{{ $invoice->seller['sac'] !== '' ? $invoice->seller['sac'] : '—' }}</td>
                <td class="num">{{ $subtotal->format() }}</td>
            </tr>
            @if ($order->discount_paise > 0)
                <tr>
                    <td colspan="2">{{ __('Discount') }}</td>
                    <td class="num">−{{ $discount->format() }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="2">{{ __('Taxable value') }}</td>
                <td class="num">{{ $taxable->format() }}</td>
            </tr>
            @if ($invoice->gst_split === \App\Enums\GstSplit::IntraState)
                <tr>
                    <td colspan="2">{{ __('CGST @ :rate%', ['rate' => $halfRate]) }}</td>
                    <td class="num">{{ $cgst->format() }}</td>
                </tr>
                <tr>
                    <td colspan="2">{{ __('SGST @ :rate%', ['rate' => $halfRate]) }}</td>
                    <td class="num">{{ $sgst->format() }}</td>
                </tr>
            @else
                <tr>
                    <td colspan="2">{{ __('IGST @ :rate%', ['rate' => $fullRate]) }}</td>
                    <td class="num">{{ $igst->format() }}</td>
                </tr>
            @endif
            <tr class="total">
                <td colspan="2">{{ __('Total') }}</td>
                <td class="num">₹{{ $total->format() }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer muted">
        {{ __('Paid online. This is a computer-generated invoice and needs no signature. Tax payable on reverse charge: No.') }}
    </p>
</body>
</html>
