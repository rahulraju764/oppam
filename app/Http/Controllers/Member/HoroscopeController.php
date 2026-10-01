<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Domain\Media\HoroscopeAccess;
use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /media/horoscope/{code} (signed, short-lived) — streams a horoscope file from the private
 * disk (M11, CLAUDE.md security rule 7). Valid signature AND HoroscopeAccess for the signed-in
 * viewer, every time; each view is audited. A foreign / missing file is a 404 either way.
 */
final class HoroscopeController extends Controller
{
    public function __invoke(Request $request, string $profile, HoroscopeAccess $access, AuditLogger $audit): StreamedResponse
    {
        $viewer = $request->user('web');
        $owner = Profile::query()->where('code', $profile)->first();
        $file = $owner?->getFirstMedia(Profile::HOROSCOPE);

        if (! $viewer instanceof User || $owner === null || $file === null || ! $access->canView($owner, $viewer)) {
            abort(404);
        }

        $audit->record('horoscope.viewed', $owner, actor: $viewer, subjectLabel: $owner->code);

        return response()->stream(function () use ($file): void {
            $stream = $file->stream();
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => (string) $file->mime_type,
            'Content-Disposition' => 'inline; filename="horoscope-'.$owner->code.'.'.$file->extension.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',   // member-supplied PDF: no scripts / forms on our origin
        ]);
    }
}
