<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\MemberSearchCriteria;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A03 unified member search + facets (list and CSV export). Members only (role MEMBER — brokers
 * get their own A07 screens). Deleted accounts appear only when the "Deleted" status is chosen
 * (the recovery view). Every filter value comes from MemberSearchCriteria (already allow-listed)
 * and is bound, never concatenated.
 */
final class MemberSearchQuery
{
    /** @return Builder<User> */
    public function build(MemberSearchCriteria $criteria): Builder
    {
        $query = User::query()
            ->select('users.*')
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('users.role', UserRole::Member->value)
            ->with(['profile' => fn ($q) => $q->withTrashed()->with(['religion:id,label', 'district:id,label'])]);

        if ($criteria->accountStatus === UserStatus::Deleted) {
            $query->onlyTrashed();
        } elseif ($criteria->accountStatus !== null) {
            $query->where('users.status', $criteria->accountStatus->value);
        }

        $this->search($query, $criteria->search);

        $query
            ->when($criteria->profileStatus, fn (Builder $q, $s) => $q->where('profiles.status', $s->value))
            ->when($criteria->verified !== null, fn (Builder $q) => $q->where('profiles.is_verified', $criteria->verified))
            ->when($criteria->completenessMin, fn (Builder $q, int $min) => $q->where('profiles.completeness', '>=', $min))
            ->when($criteria->gender, fn (Builder $q, $g) => $q->where('profiles.gender', $g->value))
            ->when($criteria->religionId, fn (Builder $q, int $id) => $q->where('profiles.religion_id', $id))
            ->when($criteria->districtId, fn (Builder $q, int $id) => $q->where('profiles.district_id', $id))
            ->when($criteria->registeredFrom, fn (Builder $q, $from) => $q->where('users.created_at', '>=', $from->startOfDay()->utc()))
            ->when($criteria->registeredTo, fn (Builder $q, $to) => $q->where('users.created_at', '<', $to->addDay()->startOfDay()->utc()));

        $this->lastActive($query, $criteria->lastActive);
        $this->plan($query, $criteria->plan);

        return match ($criteria->sort) {
            'oldest' => $query->orderBy('users.created_at')->orderBy('users.id'),
            'last_active' => $query->orderByRaw('profiles.last_active_at IS NULL')->orderByDesc('profiles.last_active_at')->orderByDesc('users.id'),
            default => $query->orderByDesc('users.created_at')->orderByDesc('users.id'),
        };
    }

    /**
     * One box for code (OPM…), email, phone (any 4+ digits, matched at the end of the number)
     * or name (prefix of first or last name).
     *
     * @param  Builder<User>  $query
     */
    private function search(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';

        match (true) {
            preg_match('/^OPM\d+$/i', $term) === 1 => $query->where('profiles.code', strtoupper($term)),
            str_contains($term, '@') => $query->where('users.email', mb_strtolower($term)),
            preg_match('/^[\d\s+()-]+$/', $term) === 1 && strlen($digits) >= 4 => $query->where('users.phone', 'like', '%'.substr($digits, -10)),
            default => $query->where(fn (Builder $q) => $q
                ->where('profiles.first_name', 'like', self::prefix($term))
                ->orWhere('profiles.last_name', 'like', self::prefix($term))),
        };
    }

    /** @param  Builder<User>  $query */
    private function lastActive(Builder $query, ?string $lastActive): void
    {
        if ($lastActive === MemberSearchCriteria::INACTIVE) {
            $query->where(fn (Builder $q) => $q->whereNull('profiles.last_active_at')
                ->orWhere('profiles.last_active_at', '<', now()->subDays(90)));
        } elseif ($lastActive !== null) {
            $query->where('profiles.last_active_at', '>=', now()->subDays(MemberSearchCriteria::LAST_ACTIVE[$lastActive]));
        }
    }

    /**
     * Plan = the member's current subscription (active, not paused, within its dates); Free = none.
     *
     * @param  Builder<User>  $query
     */
    private function plan(Builder $query, ?PlanCode $plan): void
    {
        if ($plan === null) {
            return;
        }

        $current = fn (QueryBuilder $q) => $q->select(DB::raw(1))
            ->from('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereColumn('subscriptions.profile_id', 'profiles.id')
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->whereNull('subscriptions.paused_at')
            ->where('subscriptions.starts_at', '<=', now())
            ->where('subscriptions.ends_at', '>', now())
            ->when($plan !== PlanCode::Free, fn (QueryBuilder $q) => $q->where('plans.code', $plan->value));

        $plan === PlanCode::Free ? $query->whereNotExists($current) : $query->whereExists($current);
    }

    private static function prefix(string $term): string
    {
        return addcslashes($term, '%_\\').'%';
    }
}
