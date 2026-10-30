<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\ImportFileVault;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A07 Imports monitor "source-file download (audited, until purge)" (§11A B.12, B.18): the bureau's
 * uploaded sheet or photo zip of one batch, decrypted from the vault into a private temporary copy
 * that is deleted once sent. `brokers.imports.manage` (route); the batch is found within the bureau
 * by its code; a purged, cancelled or missing file → 404. Every download is audited. The file is
 * what the bureau uploaded — served as an attachment, never rendered (nosniff).
 */
final class ImportSourceController extends Controller
{
    public function __invoke(Request $request, string $broker, string $batch, string $file, ImportFileVault $vault, AuditLogger $audit): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);

        $bureau = Broker::query()->where('code', strtoupper($broker))->firstOrFail();
        $model = BrokerImportBatch::query()->where('broker_id', $bureau->id)->where('code', $batch)->firstOrFail();
        $path = $file === 'photos' ? $model->photos_zip_path : $model->sheet_path;
        $extension = $file === 'photos' ? 'zip' : $model->sheetType();
        abort_if($path === null || $extension === null || $model->files_purged_at !== null, 404);

        try {
            $copy = $vault->unseal($path, $extension);
        } catch (RuntimeException) {
            abort(404);
        }

        // The plain copy must never outlive this request: audit failure, client abort or a fatal error.
        register_shutdown_function(static fn () => ImportFileVault::forget($copy));
        try {
            $audit->record('brokers.import_source_downloaded', $model, after: ['file' => $file, 'broker' => $bureau->code],
                actor: $admin, subjectLabel: $model->code);
        } catch (Throwable $e) {
            ImportFileVault::forget($copy);

            throw $e;
        }

        $name = $bureau->code.'-'.$model->code.'-'.($file === 'photos' ? 'photos.zip' : 'sheet.'.$extension);

        // Streamed from the private temporary copy, which is deleted as soon as it has been sent.
        return response()->streamDownload(static function () use ($copy): void {
            try {
                $in = fopen($copy, 'rb');
                if (is_resource($in)) {
                    fpassthru($in);
                    fclose($in);
                }
            } finally {
                ImportFileVault::forget($copy);
            }
        }, $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
