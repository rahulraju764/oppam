<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Invoice;

/** Renders a GST invoice as PDF bytes (M10). Implemented with dompdf (DompdfInvoiceRenderer). */
interface InvoiceRenderer
{
    public function render(Invoice $invoice): string;
}
