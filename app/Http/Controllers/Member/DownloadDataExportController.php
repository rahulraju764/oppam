<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Enums\DataExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Profile\GenerateDataExportJob;
use App\Models\DataExport;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /settings/data-export/{code} — the signed-in member downloads their own "my data" zip (M14,
 * P8.2c). The lookup is scoped to the member (another member's code → 404, never a hint it exists);
 * an expired copy → 410; not ready → 404. Streamed from the private disk, never cached. Not in an
 * admin support session (the member's data is theirs). Every download is audited.
 */
final class DownloadDataExportController extends Controller
{
    public function __invoke(Request $request, string $code, Impersonation $impersonation, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        abort_if($impersonation->isActive(), 404);

        $export = DataExport::query()->where('user_id', $user->id)->where('code', $code)->firstOrFail();

        abort_if($export->hasExpired(), 410, __('This copy of your data has expired. You can ask for a new one in your settings.'));
        abort_unless($export->status === DataExportStatus::Ready && $export->isDownloadable(), 404);

        $disk = Storage::disk(GenerateDataExportJob::disk());
        abort_unless($export->file_path !== null && $disk->exists($export->file_path), 404);

        $export->forceFill(['downloaded_at' => now()])->save();
        $audit->record('account.data_export_downloaded', $export, actor: $user, subjectLabel: $export->code);

        return $disk->download($export->file_path, 'oppam-my-data-'.now('Asia/Kolkata')->toDateString().'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
