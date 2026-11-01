<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\CreditNoteRenderer;
use App\Domain\Billing\FinancialYear;
use App\Models\CreditNote;
use App\ValueObjects\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/** The credit note PDF, from the invoice's frozen seller / buyer copy. Rendered on demand, not stored. */
final class DompdfCreditNoteRenderer implements CreditNoteRenderer
{
    public function __construct(private readonly FinancialYear $financialYear) {}

    public function render(CreditNote $note): string
    {
        $note->loadMissing(['invoice', 'refund.order']);

        return Pdf::loadView('pdf.credit-note', [
            'note' => $note,
            'invoice' => $note->invoice,
            'orderCode' => $note->refund->order->code,
            'financialYear' => $this->financialYear->label($note->financial_year),
            'issuedOn' => $note->issued_at->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y'),
            'taxable' => Money::paise($note->taxable_paise),
            'cgst' => Money::paise($note->cgst_paise),
            'sgst' => Money::paise($note->sgst_paise),
            'igst' => Money::paise($note->igst_paise),
            'total' => Money::paise($note->total_paise),
            'halfRate' => rtrim(rtrim(number_format($note->gst_rate_bps / 200, 2, '.', ''), '0'), '.'),
            'fullRate' => rtrim(rtrim(number_format($note->gst_rate_bps / 100, 2, '.', ''), '0'), '.'),
        ])->setPaper('a4')->output();
    }
}
