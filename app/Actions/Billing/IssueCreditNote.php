<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Billing\FinancialYear;
use App\Enums\InvestigationType;
use App\Models\BillingInvestigation;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Refund;
use App\Services\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;

/**
 * The GST credit note of a processed refund (A06). Number from its own row-locked series per
 * financial year, inside the settling transaction, so a rollback leaves no gap (CN-2627-000001).
 * Each GST head is the refund's share of the invoice's head, half-up to the paisa, and taxable is
 * what remains, so a note always adds up to the money returned at the invoice's rate. The note that
 * completes the invoice takes the remainder of every head, so an order's notes add up to it exactly.
 * More than the invoice is never credited: no note, an "investigate" row instead.
 */
final class IssueCreditNote
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly FinancialYear $financialYear,
    ) {}

    public function handle(Refund $refund, Invoice $invoice): ?CreditNote
    {
        $earlier = CreditNote::query()->where('invoice_id', $invoice->id)->get();
        $credited = (int) $earlier->sum('total_paise');
        $total = $refund->amount_paise;

        if ($credited + $total > $invoice->total_paise) {
            BillingInvestigation::raise(InvestigationType::RefundFailed, $refund->code.':over_invoice', [
                'refund' => $refund->code, 'amount_paise' => $total, 'problem' => 'over_invoice', 'already_credited_paise' => $credited,
            ], $refund->order_id, $refund->id);

            return null;
        }

        if ($credited + $total === $invoice->total_paise) {
            $cgst = $invoice->cgst_paise - (int) $earlier->sum('cgst_paise');
            $sgst = $invoice->sgst_paise - (int) $earlier->sum('sgst_paise');
            $igst = $invoice->igst_paise - (int) $earlier->sum('igst_paise');
            $taxable = $invoice->taxable_paise - (int) $earlier->sum('taxable_paise');
        } else {
            $share = fn (int $head): int => (int) round($head * $total / $invoice->total_paise);
            $cgst = $share($invoice->cgst_paise);
            $sgst = $share($invoice->sgst_paise);
            $igst = $share($invoice->igst_paise);
            $taxable = $total - $cgst - $sgst - $igst;
        }

        $now = CarbonImmutable::now();
        $year = $this->financialYear->of($now);
        $sequence = $this->sequences->next('credit_note:'.$year);

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
            'cgst_paise' => $cgst,
            'sgst_paise' => $sgst,
            'igst_paise' => $igst,
            'total_paise' => $total,
        ])->save();

        return $note;
    }
}
