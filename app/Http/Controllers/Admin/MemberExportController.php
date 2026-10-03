<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Members\ExportMembers;
use App\Data\Admin\MemberSearchCriteria;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /members/export?token=… (admin, signed + 5 minutes — the member list builds the link) —
 * streams the A03 CSV. The filters were left on the server under a single-use token for the admin
 * who asked (so a search term never sits in a URL); another admin, a reused or expired token is
 * a 404. ExportMembers authorizes `members.export` and audits; a refusal is a 403 (logged and
 * audited there).
 */
final class MemberExportController extends Controller
{
    public const CACHE_PREFIX = 'members-export:';

    public function __invoke(Request $request, ExportMembers $export): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof AdminUser, 403);

        $token = $request->query('token');
        $stored = is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? Cache::pull(self::CACHE_PREFIX.$token) : null;
        abort_unless(is_array($stored) && ($stored['admin'] ?? null) === $admin->id && is_array($stored['filters'] ?? null), 404);

        try {
            $rows = $export->handle($admin, MemberSearchCriteria::fromInput($stored['filters']));
        } catch (AuthorizationException) {
            abort(403);
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows Malayalam names correctly
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, 'members-'.now()->timezone((string) config('oppam.display_timezone'))->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
