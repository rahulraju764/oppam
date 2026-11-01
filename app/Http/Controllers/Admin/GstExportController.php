<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /billing/gst-export?month=2026-10 (A06 "monthly GST CSV", `billing.export` on the route,
 * audited): every invoice and credit note issued in that IST month, credit notes negative. Member
 * codes and states only: no names. Every value is system-made (numbers, codes, masters), so no
 * cell can carry a formula.
 */
final class GstExportController
{
    private const HEADER = ['document', 'number', 'date', 'against_invoice', 'order', 'member', 'place_of_supply', 'taxable', 'cgst', 'sgst', 'igst', 'total', 'refund_category'];

    public function __invoke(Request $request, AuditLogger $audit): StreamedResponse
    {
        $month = (string) $request->query('month', '');
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month) === 1, 422);

        $timezone = (string) config('oppam.display_timezone');
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $timezone) ?: abort(422);
        $from = $start->utc();
        $to = $start->addMonth()->utc();

        /** @var AdminUser $admin */
        $admin = auth('admin')->user();
        $audit->record('billing.gst_exported', after: ['month' => $month], actor: $admin, subjectLabel: $month);

        return response()->streamDownload(function () use ($from, $to, $timezone): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, self::HEADER);

            $rupees = fn (int $paise): string => number_format($paise / 100, 2, '.', '');

            Invoice::query()->where('issued_at', '>=', $from)->where('issued_at', '<', $to)->with('order:id,code')->orderBy('financial_year')->orderBy('sequence')
                ->each(function (Invoice $i) use ($out, $rupees, $timezone): void {
                    fputcsv($out, ['INVOICE', $i->number, $i->issued_at->copy()->timezone($timezone)->format('Y-m-d'), '', $i->order?->code, $i->buyer['code'] ?? '', $i->buyer['state'] ?? '',
                        $rupees($i->taxable_paise), $rupees($i->cgst_paise), $rupees($i->sgst_paise), $rupees($i->igst_paise), $rupees($i->total_paise), '']);
                });

            CreditNote::query()->where('issued_at', '>=', $from)->where('issued_at', '<', $to)->with(['invoice', 'refund.order:id,code'])->orderBy('financial_year')->orderBy('sequence')
                ->each(function (CreditNote $n) use ($out, $rupees, $timezone): void {
                    fputcsv($out, ['CREDIT_NOTE', $n->number, $n->issued_at->copy()->timezone($timezone)->format('Y-m-d'), $n->invoice->number, $n->refund->order->code, $n->invoice->buyer['code'] ?? '', $n->invoice->buyer['state'] ?? '',
                        $rupees(-$n->taxable_paise), $rupees(-$n->cgst_paise), $rupees(-$n->sgst_paise), $rupees(-$n->igst_paise), $rupees(-$n->total_paise), $n->refund->category->value]);
                });

            fclose($out);
        }, 'oppam-gst-'.$start->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
