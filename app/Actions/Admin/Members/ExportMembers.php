<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Data\Admin\MemberSearchCriteria;
use App\Models\AdminUser;
use App\Models\User;
use App\Queries\Admin\MemberSearchQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Audited CSV export of the A03 member list (same filters as the screen). Needs `members.export`;
 * a refused export is logged and audited (CLAUDE.md: exports → 403 + log). The columns are a
 * fixed allow-list: never passwords, tokens, document keys, message content — and no phone or
 * email either (decision 2026-10-02; contact data stays on the member page). Cells that a
 * spreadsheet would run as a formula are neutralised. At most MAX_ROWS rows.
 */
final class ExportMembers
{
    public const MAX_ROWS = 50_000;

    public const COLUMNS = ['Code', 'First name', 'Last name', 'Gender', 'Age', 'Religion', 'District', 'Account status',
        'Profile status', 'Verified', 'Premium', 'Completeness %', 'Registered (IST)', 'Last active (IST)'];

    public function __construct(
        private readonly MemberSearchQuery $search,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return LazyCollection<int, list<string>> the header row, then one row per member
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, MemberSearchCriteria $criteria): LazyCollection
    {
        $this->authorize($admin, $criteria);

        // Chunked by id (not cursor(): cursor drops the eager loads — one query per row — and the
        // trashed profiles of deleted members). The screen's sort doesn't matter for a file.
        $query = $this->search->build($criteria)->reorder();
        $count = min((clone $query)->count(), self::MAX_ROWS);

        $this->audit->record('members.exported', null, after: ['rows' => $count, 'filters' => $criteria->summary()], actor: $admin);

        $timezone = (string) config('oppam.display_timezone');

        return LazyCollection::make(function () use ($query, $timezone) {
            yield self::COLUMNS;

            foreach ($query->lazyById(500, 'users.id', 'id')->take(self::MAX_ROWS) as $member) {
                /** @var User $member */
                $profile = $member->profile;

                yield array_map(self::cell(...), [
                    $profile?->code,
                    $profile?->first_name,
                    $profile?->last_name,
                    $profile?->gender->label(),
                    $profile?->age(),
                    $profile?->religion?->label,
                    $profile?->district?->label,
                    $member->status->label(),
                    $profile?->status->label(),
                    $profile?->is_verified ? 'Yes' : 'No',
                    $profile?->is_premium ? 'Yes' : 'No',
                    $profile?->completeness,
                    $member->created_at?->timezone($timezone)->format('Y-m-d H:i'),
                    $profile?->last_active_at?->timezone($timezone)->format('Y-m-d H:i'),
                ]);
            }
        });
    }

    /**
     * Refuse (403) an admin without `members.export` — logged and audited (CLAUDE.md: exports →
     * 403 + log). Called before the link is built and again when the file is served.
     *
     * @throws AuthorizationException
     */
    public function authorize(AdminUser $admin, MemberSearchCriteria $criteria): void
    {
        if (Gate::forUser($admin)->denies('members.export')) {
            $this->audit->record('members.export_denied', null, after: ['filters' => $criteria->summary()], actor: $admin);
            Log::warning('Member export refused: missing members.export', ['admin_id' => $admin->id]);

            throw new AuthorizationException(__('You do not have permission to export members.'));
        }
    }

    /** CSV/formula injection: a cell starting with = + - @ (or a tab / CR) is prefixed with '. */
    private static function cell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
