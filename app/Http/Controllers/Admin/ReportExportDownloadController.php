<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdminExportStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminExport;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /reports/exports/{code} — downloads a background A09 export (P9.1a). Only the admin who asked
 * for it (another admin's code is a 404, like an unknown one), only while they still hold
 * `reports.export`, only within its 24 hours (afterwards 410). Every download is audited.
 */
final class ReportExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $code, AuditLogger $audit): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser && Gate::forUser($admin)->allows('reports.export'), 403);

        $export = AdminExport::query()->where('code', $code)->where('admin_user_id', $admin->id)->first();
        abort_if($export === null, 404);
        abort_if($export->status === AdminExportStatus::Expired || ($export->expires_at !== null && $export->expires_at->isPast()), 410);
        abort_unless($export->status === AdminExportStatus::Ready && $export->path !== null, 404);

        $disk = Storage::disk((string) config('oppam.media.private_disk'));
        abort_unless($disk->exists($export->path), 410);

        $audit->record('reports.export_downloaded', $export, after: ['report' => $export->report, 'rows' => $export->rows], actor: $admin, subjectLabel: $export->code);

        return $disk->download($export->path, $export->fileName(), [
            'Content-Type' => $export->format->mime(),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
