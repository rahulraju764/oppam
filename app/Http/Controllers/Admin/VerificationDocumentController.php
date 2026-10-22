<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentVault;
use App\Domain\Verification\DocumentViewerAccess;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Verification\DocumentWatermarker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * One side of an ID document, for the A05 viewer only (PRD M09 acceptance: "never served from a
 * public URL; access requires verification.document.view and is audited"). Reached through a
 * 10-minute signed link (route middleware `signed`) that works only in the admin session it was
 * granted to (DocumentViewerAccess) — anything else is a 404. Every fetch writes an audit row.
 * Images leave with the viewer's email + time burned in; PDFs go to pdf.js, which paints the same
 * watermark. `no-store`, `nosniff`, a sandbox CSP, and a refusal to be opened as a page of its own
 * (Sec-Fetch-Dest: the viewer loads it as an image or a fetch, never by navigation).
 */
final class VerificationDocumentController
{
    /** Browsers announce how a resource is being loaded; a document must never be navigated to or framed. */
    private const REFUSED_DESTINATIONS = ['document', 'iframe', 'frame', 'embed', 'object'];

    public function __invoke(
        Request $request,
        string $profile,
        string $side,
        DocumentViewerAccess $access,
        DocumentVault $vault,
        DocumentWatermarker $watermarker,
        AuditLogger $audit,
    ): Response {
        Gate::authorize('verification.document.view');

        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $documentSide = DocumentViewerAccess::side($side);
        $requestId = $access->grantedRequestId($profile);

        if ($documentSide === null || $requestId === null
            || in_array(strtolower((string) $request->header('Sec-Fetch-Dest')), self::REFUSED_DESTINATIONS, true)) {
            abort(404);
        }

        $verification = VerificationRequest::query()->whereKey($requestId)
            ->whereHas('profile', fn ($q) => $q->withTrashed()->where('code', $profile))
            ->first();
        $document = $verification?->documents()->where('side', $documentSide)->first();

        if ($verification === null || $document === null || $document->isPurged() || ! $access->canView($admin, $verification)) {
            abort(404);
        }

        try {
            $bytes = $vault->get((string) $document->path);
            $watermark = __(':email · :time IST · access logged', [
                'email' => $admin->email,
                'time' => now()->timezone((string) config('oppam.display_timezone'))->format('d M Y H:i'),
            ]);
            $body = $document->isPdf() ? $bytes : $watermarker->apply($bytes, $watermark);
        } catch (RuntimeException $e) {
            report($e);
            abort(404);
        }

        $audit->record('verification.document_viewed', $verification, after: ['side' => $documentSide->value, 'mime' => $document->mime],
            actor: $admin, subjectLabel: $profile);

        return response($body, 200, [
            'Content-Type' => $document->isPdf() ? 'application/pdf' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="document-'.$documentSide->slug().($document->isPdf() ? '.pdf' : '.jpg').'"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
