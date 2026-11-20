<?php

declare(strict_types=1);

namespace App\Queries\Audit;

use App\Data\Audit\AuditFilterData;
use App\Enums\AuditActorType;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Queries\Broker\BureauActivity;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The A12 audit viewer's query (P9.2a; PRD A12 "viewer with filters"). Newest first, cursor-paged
 * (the trail grows for 7 years — no OFFSET, no COUNT(*)).
 *
 * Filters resolve to indexed columns: an actor (admin email / member code / "system") → the
 * (actor_type, actor_id, created_at) index; an action or area prefix → (action, created_at); a
 * subject type → (subject_type, subject_id, created_at); a subject code → (subject_label,
 * created_at); dates are IST days. Free text uses the FULLTEXT index in boolean mode, each word a
 * required prefix term (`+refund*`); words shorter than the FTS minimum (3) are dropped.
 *
 * Unknown actors are reported back as unknown and list nothing (never "everything" by mistake).
 */
final class AuditLogQuery
{
    public const PER_PAGE = 30;

    /** InnoDB's default innodb_ft_min_token_size. */
    private const MIN_TERM = 3;

    /**
     * @return array{rows: CursorPaginator<int, AuditLog>, unknown: list<string>, ignoredTerms: bool}
     */
    public function page(AuditFilterData $filters): array
    {
        ['query' => $query, 'unknown' => $unknown, 'ignoredTerms' => $ignored] = $this->build($filters);

        return [
            'rows' => $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(self::PER_PAGE, cursorName: 'after'),
            'unknown' => $unknown,
            'ignoredTerms' => $ignored,
        ];
    }

    /**
     * The filtered query, unordered (export + page).
     *
     * @return array{query: Builder<AuditLog>, unknown: list<string>, ignoredTerms: bool}
     */
    public function build(AuditFilterData $filters): array
    {
        $query = AuditLog::query();
        $unknown = [];

        if ($filters->actor !== '' && ! $this->filterActor($query, $filters->actor)) {
            $unknown[] = 'actor';
        }

        if ($filters->action !== '') {
            $action = strtolower($filters->action);
            if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_.]+)?$/', $action) !== 1) {
                $unknown[] = 'action';
            } elseif (str_contains($action, '.')) {
                $query->where('action', $action);
            } else {
                $query->where('action', 'like', $action.'.%');   // area prefix: uses (action, created_at)
            }
        }

        if ($filters->subjectType !== '') {
            in_array($filters->subjectType, $this->subjectTypes(), true)
                ? $query->where('subject_type', $filters->subjectType)
                : $unknown[] = 'subjectType';
        }

        if ($filters->subject !== '') {
            $query->where('subject_label', $filters->subject);
        }

        $tz = (string) config('oppam.display_timezone');
        $from = BureauActivity::date($filters->from, $tz)?->startOfDay()->utc();
        $to = BureauActivity::date($filters->to, $tz)?->endOfDay()->utc();
        $query->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to));

        $ignored = false;
        if ($filters->search !== '') {
            [$terms, $ignored] = self::terms($filters->search);
            if ($terms !== '') {
                $query->whereRaw('MATCH (action, actor_label, subject_label, reason) AGAINST (? IN BOOLEAN MODE)', [$terms]);
            }
        }

        $query->when($unknown !== [], fn (Builder $q) => $q->whereRaw('1 = 0'));

        return ['query' => $query, 'unknown' => $unknown, 'ignoredTerms' => $ignored];
    }

    /**
     * Free text → a boolean-mode FULLTEXT expression: each word a required prefix term. FTS operators
     * typed by the admin are stripped (they are words here, not syntax).
     *
     * @return array{0: string, 1: bool} [expression ('' = no usable word), some words were too short]
     */
    public static function terms(string $search): array
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $usable = array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) >= self::MIN_TERM)));

        return [
            implode(' ', array_map(fn (string $w): string => '+'.$w.'*', array_slice($usable, 0, 8))),
            count($usable) < count(array_unique($words)),
        ];
    }

    /**
     * Subject types present in the trail (the subject-type filter's allow-list), cached 10 minutes.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        /** @var list<string> */
        return Cache::remember('audit:subject-types', 600, fn (): array => DB::table('audit_logs')
            ->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')->map(fn ($t): string => (string) $t)->all());
    }

    /**
     * Area prefixes present in the trail ("billing", "brokers", …), cached 10 minutes.
     *
     * @return list<string>
     */
    public function areas(): array
    {
        /** @var list<string> */
        return Cache::remember('audit:areas', 600, fn (): array => DB::table('audit_logs')->distinct()->pluck('action')
            ->map(fn ($a): string => explode('.', (string) $a)[0])->unique()->sort()->values()->all());
    }

    /**
     * Display names for the actors on a page: admins by the email stored with the row; members by
     * their profile code (one query) — never a ULID.
     *
     * @param  iterable<AuditLog>  $rows
     * @return array<string, string> audit row id => actor name
     */
    public function actorNames(iterable $rows): array
    {
        $rows = Collection::make($rows);
        $memberIds = $rows->filter(fn (AuditLog $r): bool => $r->actor_type === AuditActorType::User && $r->actor_id !== null)
            ->pluck('actor_id')->unique()->values()->all();
        $codes = $memberIds === [] ? [] : Profile::withTrashed()->whereIn('user_id', $memberIds)->pluck('code', 'user_id')->all();

        return $rows->mapWithKeys(fn (AuditLog $r): array => [$r->id => match ($r->actor_type) {
            AuditActorType::Admin => $r->actor_label ?? __('Admin'),
            AuditActorType::User => isset($codes[$r->actor_id]) ? __('Member :code', ['code' => $codes[$r->actor_id]]) : __('Member'),
            AuditActorType::System => __('System'),
        }])->all();
    }

    /** @param  Builder<AuditLog>  $query */
    private function filterActor(Builder $query, string $actor): bool
    {
        if (strtolower($actor) === 'system') {
            $query->where('actor_type', AuditActorType::System->value);

            return true;
        }

        if (str_contains($actor, '@')) {
            $id = AdminUser::query()->where('email', $actor)->value('id');
            $id !== null && $query->where('actor_type', AuditActorType::Admin->value)->where('actor_id', $id);

            return $id !== null;
        }

        $userId = Profile::withTrashed()->where('code', strtoupper($actor))->value('user_id');
        $userId !== null && $query->where('actor_type', AuditActorType::User->value)->where('actor_id', $userId);

        return $userId !== null;
    }
}
