<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\ExportAuditLog;
use App\Data\Audit\AuditFilterData;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Queries\Audit\AuditLogQuery;
use App\Services\Audit\AuditLogger;
use App\Support\Export\SafeCell;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /audit/export?token=… (admin, signed + 5 minutes; built by the audit viewer) — streams the
 * filtered A12 audit trail as CSV (P9.2a). The filters and row count were left on the server under
 * a single-use token for the admin who asked: another admin, a reused or expired token is a 404.
 * `audit.export` is checked again here. Cells go through SafeCell (no formula can start a cell);
 * before / after are the stored JSON, redacted again (as in the viewer's diff).
 */
final class AuditExportController extends Controller
{
    public const CACHE_PREFIX = 'audit-export:';

    private const CHUNK = 1000;

    public function __invoke(Request $request, ExportAuditLog $export, AuditLogQuery $audit, AuditLogger $logger): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);

        $token = $request->query('token');
        $stored = is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? Cache::pull(self::CACHE_PREFIX.$token) : null;
        abort_unless(is_array($stored) && ($stored['admin'] ?? null) === $admin->id && is_array($stored['filters'] ?? null), 404);

        try {
            $export->authorize($admin);
        } catch (AuthorizationException) {
            abort(403);
        }

        $filters = AuditFilterData::fromArray($stored['filters']);
        $max = Settings::int(SettingKey::SystemAuditExportMaxRows);
        $export->streamed($admin, $filters, (int) ($stored['rows'] ?? 0));
        $tz = (string) config('oppam.display_timezone');

        return response()->streamDownload(function () use ($audit, $logger, $filters, $max, $tz): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['When (IST)', 'Actor type', 'Actor', 'Action', 'Subject type', 'Subject', 'Reason', 'IP address', 'Before', 'After'], escape: '');
            $written = 0;

            $audit->build($filters)['query']->chunkByIdDesc(self::CHUNK, function (Collection $rows) use ($out, $audit, $logger, $tz, $max, &$written): bool {
                $names = $audit->actorNames($rows);
                foreach ($rows as $row) {
                    /** @var AuditLog $row */
                    if ($written >= $max) {
                        return false;
                    }
                    fputcsv($out, array_map(SafeCell::neutralise(...), [
                        $row->created_at->timezone($tz)->format('Y-m-d H:i:s'),
                        $row->actor_type->label(),
                        $names[$row->id] ?? '',
                        $row->action,
                        $row->subject_type,
                        $row->subject_label,
                        $row->reason,
                        $row->getAttribute('ip_address'),
                        $row->before === null ? '' : (string) json_encode($logger->redact($row->before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $row->after === null ? '' : (string) json_encode($logger->redact($row->after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]), escape: '');
                    $written++;
                }

                return true;
            });
            fclose($out);
        }, 'audit-log-'.now($tz)->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
