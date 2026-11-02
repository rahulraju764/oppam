<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Contracts\CreditNoteRenderer;
use App\Models\CreditNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /billing/credit-notes/{number} (A06, decisions 2026-11-01): the member's own credit note only —
 * anyone else's number is a 404. Rendered on demand, never cached.
 */
final class CreditNoteDownloadController
{
    public function __invoke(Request $request, string $number, CreditNoteRenderer $renderer): Response
    {
        /** @var User $member */
        $member = $request->user();
        $profile = $member->profile ?? abort(404);

        $note = CreditNote::query()->where('number', $number)->where('profile_id', $profile->id)->first() ?? abort(404);

        return response($renderer->render($note), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$note->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
