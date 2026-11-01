<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\CreditNoteRenderer;
use App\Models\CreditNote;
use Illuminate\Http\Response;

/** GET /billing/credit-notes/{number} (A06, `billing.view` on the route): the PDF, never cached. */
final class CreditNoteController
{
    public function __invoke(string $number, CreditNoteRenderer $renderer): Response
    {
        $note = CreditNote::query()->where('number', $number)->firstOrFail();

        return response($renderer->render($note), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$note->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
