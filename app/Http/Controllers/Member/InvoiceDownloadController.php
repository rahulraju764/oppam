<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Contracts\InvoiceRenderer;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /billing/invoices/{number} (PRD §13: controller download). The member's own invoice only —
 * anyone else's number is a 404. Private disk, streamed, never cached; rendered on demand if the
 * queued PDF job hasn't run yet.
 */
final class InvoiceDownloadController
{
    public function __invoke(Request $request, string $invoice, InvoiceRenderer $renderer): StreamedResponse
    {
        /** @var User $member */
        $member = $request->user();
        $profile = $member->profile ?? abort(404);

        $row = Invoice::query()->where('number', $invoice)->where('profile_id', $profile->id)->first() ?? abort(404);
        $path = GenerateInvoicePdf::ensure($row, $renderer);

        return Storage::disk(GenerateInvoicePdf::DISK)->download($path, $row->number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
