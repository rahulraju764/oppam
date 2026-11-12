<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ExportReport;
use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\ReportRegistry;
use App\Enums\ExportFormat;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\Reports\ReportFileWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /reports/export?token=… (admin, signed + 5 minutes; built by the report page) — streams a
 * small A09 report export now (P9.1a). The report, period and format were left on the server
 * under a single-use token for the admin who asked: another admin, a reused or expired token is a
 * 404. `reports.export` is checked again here (ExportReport::authorize — a refusal is audited).
 */
final class ReportExportController extends Controller
{
    public const CACHE_PREFIX = 'report-export:';

    public function __invoke(Request $request, ExportReport $export, ReportRegistry $reports, ReportFileWriter $writer): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);

        $token = $request->query('token');
        $stored = is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? Cache::pull(self::CACHE_PREFIX.$token) : null;
        abort_unless(is_array($stored) && ($stored['admin'] ?? null) === $admin->id, 404);

        $key = ReportKey::from($stored['report']);
        $range = ReportRange::of($stored['from'], $stored['to']);
        $format = ExportFormat::from($stored['format']);

        try {
            $export->authorize($admin, $key);
        } catch (AuthorizationException) {
            abort(403);
        }

        $title = $key->label().' — '.$range->label();
        $name = $key->value.'-'.$range->from->toDateString().'-to-'.$range->to->toDateString().'.'.$format->value;

        // Audited BEFORE the first byte goes out: a download the client aborts half-way is still on record.
        $export->streamed($admin, $key, $range, $format, $reports->for($key)->exportRows($range));

        return response()->streamDownload(function () use ($writer, $reports, $key, $range, $format, $title): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $writer->write($reports->for($key)->exportTables($range), $format, $out, $title);
            fclose($out);
        }, $name, [
            'Content-Type' => $format->mime(),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
