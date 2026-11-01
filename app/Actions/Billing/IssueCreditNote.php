<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Billing\FinancialYear;
use App\Enums\GstSplit;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Refund;
use App\Services\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;

/**
 * The GST credit note of a refund (A06). Number from its own row-locked series per financial year,
 * inside the settling transaction, so a rollback leaves no gap (CN-2627-000001). Taxable and GST are
 * the refund's share of the invoice, half-up to the paisa; the refund that returns the rest takes
 * whatever is left of the invoice, so the notes of an order always add up to it exactly.
 */
final class IssueCreditNote
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly FinancialYear $financialYear,
    ) {}

    public function handle(Refund $refund, Invoice $invoice): CreditNote
    {
        $now = CarbonImmutable::now();
        $year = $this->financialYear->of($now);
        $sequence = $this->sequences->next('credit_note:'.$year);

        $earlier = CreditNote::query()->where('invoice_id', $invoice->id);
        $total = $refund->amount_paise;
        $taxable = $refund->ends_plan
            ? max(0, $invoice->taxable_paise - (int) (clone $earlier)->sum('taxable_paise'))
            : (int) round($total * 10_000 / (10_000 + $invoice->gst_rate_bps));
        $gst = max(0, $total - $taxable);
        $intra = $invoice->gst_split === GstSplit::IntraState;
        $central = intdiv($gst, 2);

        $note = new CreditNote;
        $note->forceFill([
            'number' => sprintf('CN-%s-%06d', $year, $sequence),
            'financial_year' => $year,
            'sequence' => $sequence,
            'refund_id' => $refund->id,
            'invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'profile_id' => $invoice->profile_id,
            'issued_at' => $now,
            'gst_split' => $invoice->gst_split,
            'taxable_paise' => $taxable,
            'gst_rate_bps' => $invoice->gst_rate_bps,
            'cgst_paise' => $intra ? $central : 0,
            'sgst_paise' => $intra ? $gst - $central : 0,
            'igst_paise' => $intra ? 0 : $gst,
            'total_paise' => $total,
        ])->save();

        return $note;
    }
}
