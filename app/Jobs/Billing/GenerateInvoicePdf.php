<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Contracts\InvoiceRenderer;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a paid order's GST invoice to the PRIVATE disk (M10, F04). Idempotent: an invoice that
 * already has its file is left alone; the download route renders on demand if this hasn't run yet.
 */
final class GenerateInvoicePdf implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DISK = 'local';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $invoiceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(InvoiceRenderer $renderer): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if ($invoice !== null) {
            self::ensure($invoice, $renderer);
        }
    }

    /** The invoice's PDF path on the private disk, rendering it first when it doesn't exist yet. */
    public static function ensure(Invoice $invoice, InvoiceRenderer $renderer): string
    {
        $disk = Storage::disk(self::DISK);
        if ($invoice->pdf_path !== null && $disk->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        $path = 'invoices/'.$invoice->financial_year.'/'.$invoice->number.'.pdf';
        $disk->put($path, $renderer->render($invoice));
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
