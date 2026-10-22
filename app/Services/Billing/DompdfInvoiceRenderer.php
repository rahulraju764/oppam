<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\InvoiceRenderer;
use App\Domain\Billing\FinancialYear;
use App\Models\Invoice;
use App\ValueObjects\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The GST invoice PDF (resources/views/pdf/invoice.blade.php) via barryvdh/laravel-dompdf (PRD §4).
 * Everything printed comes from the invoice row's frozen copy — never from today's settings.
 */
final class DompdfInvoiceRenderer implements InvoiceRenderer
{
    public function __construct(private readonly FinancialYear $financialYear) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('order.plan');
        $order = $invoice->order;

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'order' => $order,
            'financialYear' => $this->financialYear->label($invoice->financial_year),
            'issuedOn' => $invoice->issued_at->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y'),
            'subtotal' => Money::paise($order->subtotal_paise),
            'discount' => Money::paise($order->discount_paise),
            'taxable' => Money::paise($invoice->taxable_paise),
            'cgst' => Money::paise($invoice->cgst_paise),
            'sgst' => Money::paise($invoice->sgst_paise),
            'igst' => Money::paise($invoice->igst_paise),
            'total' => Money::paise($invoice->total_paise),
            'halfRate' => rtrim(rtrim(number_format($invoice->gst_rate_bps / 200, 2, '.', ''), '0'), '.'),
            'fullRate' => rtrim(rtrim(number_format($invoice->gst_rate_bps / 100, 2, '.', ''), '0'), '.'),
        ])->setPaper('a4')->output();
    }
}
