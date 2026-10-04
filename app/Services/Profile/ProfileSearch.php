<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Data\Search\SearchCriteria;
use App\Data\Search\SearchPage;
use App\Domain\Safety\BlockList;
use App\Enums\CreatedFor;
use App\Enums\Dosham;
use App\Enums\Gender;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Enums\SearchSort;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Masters\Country;
use App\Models\Profile;
use App\Services\Masters\Masters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * THE profile search query (M04). Always applied, whatever the filters: ACTIVE (published)
 * profiles of the opposite gender (an ACTIVE profile always has an active member account —
 * SuspendMember / DeleteMember change both; page() re-checks the account), never the searcher,
 * never a profile in a
 * blocked pair (either direction, BlockList), never one the searcher ignored, never an incognito
 * profile. Every filter value comes from SearchCriteria (allow-listed) and is bound.
 *
 * Results are keyset-paginated ("load more"): ordered by the sort key, then published_at, then id,
 * and the next page starts strictly after the last row — stable while new profiles arrive, and
 * no OFFSET scan. Relevance = how many of the searcher's partner preferences the profile meets on
 * its own columns (age, height, marital / physical status, religion, caste, mother tongue, star,
 * district), plus a boost for premium and highlighted profiles (owner decision 2026-10-04).
 */
final class ProfileSearch
{
    public const PER_PAGE = 20;

    private const EPOCH = '1970-01-01 00:00:00';

    public function __construct(
        private readonly BlockList $blocks,
        private readonly Masters $masters,
    ) {}

    /**
     * The filtered result set (no order, no paging).
     *
     * @return Builder<Profile>
     */
    public function query(Profile $searcher, SearchCriteria $criteria): Builder
    {
        // withTrashed(): a soft-deleted profile always has status DELETED, so `status = ACTIVE`
        // already excludes it — and leaving `deleted_at IS NULL` out keeps every search index
        // covering (deleted_at is in none of them; that condition alone cost seconds at 100k).
        $query = Profile::withTrashed()
            ->select('profiles.*')
            ->where('profiles.status', ProfileStatus::Active->value)
            ->where('profiles.gender', $this->oppositeGender($searcher)->value)
            ->where('profiles.id', '!=', $searcher->id)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('privacy_settings')
                ->whereColumn('privacy_settings.profile_id', 'profiles.id')
                ->where('privacy_settings.incognito', true))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('ignores')
                ->whereColumn('ignores.ignored_profile_id', 'profiles.id')
                ->where('ignores.ignorer_profile_id', $searcher->id));

        $hidden = $this->blocks->hiddenFrom($searcher);
        if ($hidden !== []) {
            $query->whereNotIn('profiles.id', $hidden);
        }

        $this->basics($query, $criteria);
        $this->community($query, $criteria);
        $this->career($query, $criteria);
        $this->familyAndLifestyle($query, $criteria);
        $this->quality($query, $searcher, $criteria);

        return $query;
    }

    /**
     * The number shown above the results. Like query() it trusts that an ACTIVE profile has an
     * active account (page() re-checks), so in an inconsistent row's case it may count one card
     * page() then leaves out.
     */
    public function count(Profile $searcher, SearchCriteria $criteria): int
    {
        return $this->query($searcher, $criteria)->count();
    }

    /**
     * The ids of the best `$limit` profiles by relevance (the daily-matches candidate pool, F06):
     * ranked in SQL, so the pool is the closest fits, not the first rows by id.
     *
     * @param  list<string>  $excludeIds
     * @return list<string>
     */
    public function topIds(Profile $searcher, SearchCriteria $criteria, int $limit, array $excludeIds = []): array
    {
        [$keySql, $keyBindings] = $this->relevance($searcher);

        return $this->query($searcher, $criteria)
            ->when($excludeIds !== [], fn (Builder $q) => $q->whereNotIn('profiles.id', $excludeIds))
            ->select('profiles.id')
            ->orderByRaw("{$keySql} DESC", $keyBindings)
            ->orderByDesc('profiles.last_active_at')
            ->orderByDesc('profiles.id')
            ->limit($limit)
            ->toBase()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function page(Profile $searcher, SearchCriteria $criteria, ?string $cursor = null): SearchPage
    {
        // The sort key as an SQL expression (with its bindings); published_at then id break ties.
        [$keySql, $keyBindings] = match ($criteria->sort) {
            SearchSort::Relevance => $this->relevance($searcher),
            SearchSort::Newest => ['profiles.published_at', []],
            SearchSort::LastActive => ['COALESCE(profiles.last_active_at, ?)', [self::EPOCH]],
            SearchSort::Verified => ['(CASE WHEN profiles.is_verified THEN 1 ELSE 0 END)', []],
        };

        // Step 1 — a narrow query: only the id and the sort keys of the next PER_PAGE + 1 rows.
        // Sorting narrow rows is cheap, and Newest walks the (status, gender, published_at) index.
        $keys = $this->query($searcher, $criteria)
            ->select('profiles.id', 'profiles.published_at')
            ->selectRaw("{$keySql} AS search_key", $keyBindings);

        $after = $this->decodeCursor($cursor);
        if ($after !== null) {
            [$k, $published, $id] = $after;
            $keys->whereRaw(
                "({$keySql} < ? OR ({$keySql} = ? AND profiles.published_at < ?) OR ({$keySql} = ? AND profiles.published_at = ? AND profiles.id < ?))",
                [...$keyBindings, $k, ...$keyBindings, $k, $published, ...$keyBindings, $k, $published, $id],
            );
        }

        $found = $keys->orderByRaw("{$keySql} DESC", $keyBindings)
            ->orderByDesc('profiles.published_at')->orderByDesc('profiles.id')
            ->limit(self::PER_PAGE + 1)->toBase()->get();

        $more = $found->count() > self::PER_PAGE;
        $found = $found->take(self::PER_PAGE)->values();
        $last = $found->last();

        // Step 2 — load just these profiles, in that order. The owning account is re-checked here
        // (an ACTIVE profile always has an active account — suspend / delete change both).
        $order = $found->pluck('id')->map(fn ($id): string => (string) $id)->flip();
        $profiles = $order->isEmpty() ? collect() : Profile::query()
            ->whereIn('profiles.id', $order->keys()->all())
            ->whereHas('user', fn (Builder $q) => $q->where('role', UserRole::Member->value)->where('status', UserStatus::Active->value))
            ->with('privacySetting')
            ->get()
            ->sortBy(fn (Profile $profile): int => (int) $order[(string) $profile->id])
            ->values();

        return new SearchPage(
            $profiles,
            $more && $last !== null
                ? $this->encodeCursor($last->search_key, (string) $last->published_at, (string) $last->id)
                : null,
        );
    }

    /** @param  Builder<Profile>  $query */
    private function basics(Builder $query, SearchCriteria $c): void
    {
        $today = now((string) config('oppam.display_timezone'))->startOfDay();

        $query
            ->when($c->ageMin, fn (Builder $q, int $min) => $q->where('profiles.dob', '<=', $today->copy()->subYears($min)->toDateString()))
            ->when($c->ageMax, fn (Builder $q, int $max) => $q->where('profiles.dob', '>', $today->copy()->subYears($max + 1)->toDateString()))
            ->when($c->heightMin, fn (Builder $q, int $min) => $q->where('profiles.height_cm', '>=', $min))
            ->when($c->heightMax, fn (Builder $q, int $max) => $q->where('profiles.height_cm', '<=', $max))
            ->when($c->maritalStatuses !== [], fn (Builder $q) => $q->whereIn('profiles.marital_status', array_map(fn ($s): string => $s->value, $c->maritalStatuses)))
            ->when($c->noChildren, fn (Builder $q) => $q->where('profiles.children_count', 0))
            ->when($c->physicalStatus, fn (Builder $q, $s) => $q->where('profiles.physical_status', $s->value))
            ->when($c->motherTongueIds !== [], fn (Builder $q) => $q->whereIn('profiles.mother_tongue_id', $c->motherTongueIds));
    }

    /** @param  Builder<Profile>  $query */
    private function community(Builder $query, SearchCriteria $c): void
    {
        $query
            ->when($c->religionId, fn (Builder $q, int $id) => $q->where('profiles.religion_id', $id))
            ->when($c->religionIds !== [], fn (Builder $q) => $q->whereIn('profiles.religion_id', $c->religionIds))
            ->when($c->casteIds !== [], fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('profiles.caste_id', $c->casteIds)
                ->when($c->includeCasteNoBar, fn (Builder $x) => $x->orWhere('profiles.caste_no_bar', true))))
            ->when($c->subCaste, fn (Builder $q, string $s) => $q->where('profiles.sub_caste', 'like', addcslashes($s, '%_\\').'%'))
            ->when($c->starIds !== [], fn (Builder $q) => $q->whereIn('profiles.star_id', $c->starIds))
            ->when($c->rasiId, fn (Builder $q, int $id) => $q->where('profiles.rasi_id', $id))
            ->when($c->districtIds !== [], fn (Builder $q) => $q->whereIn('profiles.district_id', $c->districtIds))
            ->when($c->noDosham, fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('horoscope_details')
                ->whereColumn('horoscope_details.profile_id', 'profiles.id')
                ->where('horoscope_details.chovva_dosham', Dosham::Yes->value)));
    }

    /**
     * Location (current), education and career — one EXISTS on education_careers.
     *
     * @param  Builder<Profile>  $query
     */
    private function career(Builder $query, SearchCriteria $c): void
    {
        $conditions = [];

        if ($c->countryId !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->where('ec.current_country_id', $c->countryId);
        }
        if ($c->stateId !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->where('ec.current_state_id', $c->stateId);
        }
        if ($c->nriOnly) {
            $india = (int) Country::query()->where('code', 'IN')->value('id');
            $conditions[] = fn (QueryBuilder $q) => $q->whereNotNull('ec.current_country_id')->where('ec.current_country_id', '!=', $india);
        }
        if ($c->citizenship !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->where('ec.citizenship', 'like', addcslashes($c->citizenship, '%_\\').'%');
        }
        if ($c->educationMinId !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->whereIn('ec.education_id', $this->atOrAbove($this->masters->education(), $c->educationMinId));
        }
        if ($c->occupationIds !== []) {
            $conditions[] = fn (QueryBuilder $q) => $q->whereIn('ec.occupation_id', $c->occupationIds);
        }
        if ($c->employerType !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->where('ec.employer_type', $c->employerType->value);
        }
        if ($c->incomeMinId !== null) {
            $conditions[] = fn (QueryBuilder $q) => $q->whereIn('ec.income_band_id', $this->atOrAbove($this->masters->incomeBands(), $c->incomeMinId));
        }

        $this->existsWith($query, 'education_careers', $conditions);
    }

    /** @param  Builder<Profile>  $query */
    private function familyAndLifestyle(Builder $query, SearchCriteria $c): void
    {
        $family = [];
        foreach (['family_status_option_id' => $c->familyStatusId, 'family_type_option_id' => $c->familyTypeId, 'family_values_option_id' => $c->familyValuesId] as $column => $id) {
            if ($id !== null) {
                $family[] = fn (QueryBuilder $q) => $q->where('ec.'.$column, $id);
            }
        }
        $this->existsWith($query, 'family_details', $family);

        $lifestyle = [];
        foreach (['diet_option_id' => $c->dietId, 'smoking_option_id' => $c->smokingId, 'drinking_option_id' => $c->drinkingId] as $column => $id) {
            if ($id !== null) {
                $lifestyle[] = fn (QueryBuilder $q) => $q->where('ec.'.$column, $id);
            }
        }
        $this->existsWith($query, 'lifestyle_details', $lifestyle);
    }

    /** @param  Builder<Profile>  $query */
    private function quality(Builder $query, Profile $searcher, SearchCriteria $c): void
    {
        $query
            ->when($c->verifiedOnly, fn (Builder $q) => $q->where('profiles.is_verified', true))
            ->when($c->premiumOnly, fn (Builder $q) => $q->where('profiles.is_premium', true))
            ->when($c->activeWithin, fn (Builder $q, string $w) => $q->where('profiles.last_active_at', '>=', now()->subDays(SearchCriteria::ACTIVE_WITHIN[$w])))
            ->when($c->newlyJoined, fn (Builder $q) => $q->where('profiles.published_at', '>=', now()->subDays(7)))
            ->when($c->withPhoto, fn (Builder $q) => $q->whereExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('media')
                ->where('media.model_type', $searcher->getMorphClass())
                ->whereColumn('media.model_id', 'profiles.id')
                ->where('media.collection_name', Profile::PHOTOS)
                ->where('media.moderation_status', PhotoStatus::Approved->value)))
            ->when($c->createdBy, fn (Builder $q, string $by) => $q->whereExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('users')
                ->whereColumn('users.id', 'profiles.user_id')
                ->when($by === 'self',
                    fn (QueryBuilder $y) => $y->where('users.created_for', CreatedFor::Self->value),
                    fn (QueryBuilder $y) => $y->whereIn('users.created_for', SearchCriteria::familyCreators()))))
            ->when($c->hideViewed, fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('profile_views')
                ->whereColumn('profile_views.viewed_profile_id', 'profiles.id')
                ->where('profile_views.viewer_profile_id', $searcher->id)))
            ->when($c->viewedOnly, fn (Builder $q) => $q->whereExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('profile_views')
                ->whereColumn('profile_views.viewed_profile_id', 'profiles.id')
                ->where('profile_views.viewer_profile_id', $searcher->id)))
            ->when($c->mutualOnly, fn (Builder $q) => $this->acceptsSearcher($q, $searcher));
    }

    /**
     * Mutual fit (M05): the profile's own partner preferences accept the searcher on the hard
     * fields — age, religion, marital status (F06). A preference left empty means "any"; a
     * profile with no preferences at all accepts everyone. JSON lists may hold ids as numbers or
     * strings, so both spellings are tried.
     *
     * @param  Builder<Profile>  $query
     */
    private function acceptsSearcher(Builder $query, Profile $searcher): void
    {
        $age = $searcher->age();

        $query->whereNotExists(function (QueryBuilder $pp) use ($searcher, $age): void {
            $pp->selectRaw('1')->from('partner_preferences', 'pp')
                ->whereColumn('pp.profile_id', 'profiles.id')
                ->where(function (QueryBuilder $rejects) use ($searcher, $age): void {
                    if ($age === null) {
                        $rejects->whereNotNull('pp.age_min')->orWhereNotNull('pp.age_max');
                    } else {
                        $rejects->where('pp.age_min', '>', $age)->orWhere('pp.age_max', '<', $age);
                    }

                    $this->rejectsValue($rejects, 'pp.religion_ids', $searcher->religion_id);
                    $this->rejectsValue($rejects, 'pp.marital_statuses', $searcher->marital_status?->value);
                });
        });
    }

    /** OR: the JSON list column is set (non-empty) and does not contain $value. */
    private function rejectsValue(QueryBuilder $rejects, string $column, int|string|null $value): void
    {
        $rejects->orWhere(function (QueryBuilder $q) use ($column, $value): void {
            $q->whereNotNull($column)->whereRaw("JSON_LENGTH({$column}) > 0");

            if ($value !== null) {
                $q->whereRaw("NOT JSON_CONTAINS({$column}, ?)", [json_encode($value)])
                    ->whereRaw("NOT JSON_CONTAINS({$column}, ?)", [json_encode((string) $value)]);
            }
        });
    }

    /**
     * Relevance: the searcher's partner preferences that a profile meets on its own columns,
     * ×10, + 5 when highlighted, + 3 when premium. Returns SQL with ? placeholders and bindings.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function relevance(Profile $searcher): array
    {
        $pref = $searcher->partnerPreference()->first();
        $parts = [];
        $bindings = [];

        if ($pref !== null) {
            if ($pref->age_min !== null || $pref->age_max !== null) {
                $today = now((string) config('oppam.display_timezone'))->startOfDay();
                $parts[] = 'profiles.dob <= ? AND profiles.dob > ?';
                array_push($bindings, $today->copy()->subYears($pref->age_min ?? SearchCriteria::AGE_MIN)->toDateString(),
                    $today->copy()->subYears(($pref->age_max ?? SearchCriteria::AGE_MAX) + 1)->toDateString());
            }
            if ($pref->height_min_cm !== null || $pref->height_max_cm !== null) {
                $parts[] = 'profiles.height_cm BETWEEN ? AND ?';
                array_push($bindings, $pref->height_min_cm ?? 0, $pref->height_max_cm ?? 999);
            }
            foreach ([
                'marital_status' => $pref->marital_statuses, 'physical_status' => $pref->physical_statuses,
                'religion_id' => $pref->religion_ids, 'caste_id' => $pref->caste_ids, 'mother_tongue_id' => $pref->mother_tongue_ids,
                'star_id' => $pref->star_ids, 'district_id' => $pref->district_ids,
            ] as $column => $values) {
                $values = array_values(array_filter(is_array($values) ? $values : [], 'is_scalar'));
                if ($values !== []) {
                    $parts[] = "profiles.{$column} IN (".implode(',', array_fill(0, count($values), '?')).')';
                    array_push($bindings, ...$values);
                }
            }
        }

        $matches = $parts === [] ? '0' : implode(' + ', array_map(fn (string $p): string => "(CASE WHEN {$p} THEN 1 ELSE 0 END)", $parts));
        $bindings[] = now()->toDateTimeString();

        return ["(({$matches}) * 10 + (CASE WHEN profiles.highlighted_until > ? THEN 5 ELSE 0 END) + (CASE WHEN profiles.is_premium THEN 3 ELSE 0 END))", $bindings];
    }

    /**
     * One EXISTS subquery on a profile-detail table for all of its conditions (alias `ec`).
     *
     * @param  Builder<Profile>  $query
     * @param  list<callable(QueryBuilder): mixed>  $conditions
     */
    private function existsWith(Builder $query, string $table, array $conditions): void
    {
        if ($conditions === []) {
            return;
        }

        $query->whereExists(function (QueryBuilder $q) use ($table, $conditions): void {
            $q->selectRaw('1')->from($table, 'ec')->whereColumn('ec.profile_id', 'profiles.id');
            foreach ($conditions as $condition) {
                $condition($q);
            }
        });
    }

    /**
     * Ids from a master list ordered by level, from $id upwards (education / income minimum).
     *
     * @param  list<\App\Data\Masters\MasterItem>  $items
     * @return list<int>
     */
    private function atOrAbove(array $items, int $id): array
    {
        $ids = array_map(fn ($item): int => $item->id, $items);
        $from = array_search($id, $ids, true);

        return $from === false ? [0] : array_slice($ids, $from);
    }

    private function oppositeGender(Profile $searcher): Gender
    {
        return $searcher->gender === Gender::Male ? Gender::Female : Gender::Male;
    }

    private function encodeCursor(mixed $key, string $published, string $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$key, $published, $id])), '+/', '-_'), '=');
    }

    /** @return array{0: int|string, 1: string, 2: string}|null a tampered / unreadable cursor = first page */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 200) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || count($decoded) !== 3
            || ! (is_int($decoded[0]) || (is_string($decoded[0]) && preg_match('/^[0-9: -]{1,19}$/', $decoded[0]) === 1))
            || ! is_string($decoded[1]) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $decoded[1]) !== 1
            || ! is_string($decoded[2]) || preg_match('/^[0-9A-Za-z]{26}$/', $decoded[2]) !== 1) {
            return null;
        }

        return [$decoded[0], $decoded[1], $decoded[2]];
    }
}
