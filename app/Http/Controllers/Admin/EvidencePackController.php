<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Safety\ExportEvidencePack;
use App\Http\Controllers\Controller;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /safety/cases/{case}/evidence-pack (admin, signed, 5 minutes — the case review builds the
 * link) — the A10 evidence pack zip. ExportEvidencePack checks super admin + legal hold and audits;
 * a refusal is a 403 and is logged. The temporary file is deleted after it is sent.
 */
final class EvidencePackController extends Controller
{
    public function __invoke(Request $request, string $case, ExportEvidencePack $export): BinaryFileResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);
        $abuseCase = AbuseCase::query()->where('code', $case)->firstOrFail();

        try {
            $path = $export->handle($admin, $abuseCase);
        } catch (AuthorizationException) {
            Log::warning('Evidence pack refused', ['admin' => $admin->id, 'case' => $abuseCase->code]);
            abort(403);
        }

        return response()->download($path, 'evidence-'.$abuseCase->code.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
    }
}
