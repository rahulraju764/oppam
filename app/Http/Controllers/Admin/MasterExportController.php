<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Masters\MasterLists;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /masters/{list}/export[?parent=id] (admin, masters.view) — the list (or one parent's part
 * of it) as CSV in exactly the columns the import reads: code, label, label_ml, sort_order,
 * is_active. Master data only — no member data. Audited.
 */
final class MasterExportController extends Controller
{
    public function __invoke(Request $request, string $list, AuditLogger $audit): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser && $admin->can('masters.view'), 403);

        $masterList = MasterLists::find($list) ?? abort(404);
        $parentId = $masterList->hasParent() ? (int) $request->integer('parent') : null;
        if ($masterList->hasParent()) {
            $parentList = MasterLists::find((string) $masterList->parentKey);
            abort_unless($parentList !== null && $parentList->anyParentQuery()->whereKey($parentId)->exists(), 404);
        }

        $rows = $masterList->query($parentId)->orderBy('sort_order')->orderBy('label')->get();
        $audit->record('masters.exported', null, after: ['list' => $masterList->key, 'parent' => $parentId, 'rows' => $rows->count()], actor: $admin, subjectLabel: $masterList->key);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows Malayalam labels correctly
            fputcsv($out, ['code', 'label', 'label_ml', 'sort_order', 'is_active'], escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $cell): string => $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'".$cell : $cell,
                    [$row->code, $row->label, (string) $row->label_ml, (string) $row->sort_order, $row->is_active ? 'yes' : 'no']), escape: '');
            }
            fclose($out);
        }, 'masters-'.$masterList->key.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
