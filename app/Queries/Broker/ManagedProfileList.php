<?php

declare(strict_types=1);

namespace App\Queries\Broker;

use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauStaffStatus;
use App\Enums\InterestStatus;
use App\Enums\ModerationItemType;
use App\Enums\ProfileStatus;
use App\Models\BrokerClientNote;
use App\Models\BrokerStaff;
use App\Models\Interest;
use App\Models\ModerationItem;
use App\Models\Profile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The bureau's managed-profile reads (§11A B.4 / B.6), always inside ManagedProfileScope: the
 * filtered list (status / deleted, name or code search, assigned staff), the dashboard counts and
 * the latest moderation decisions. Filters arrive allow-listed; the search is a plain LIKE with
 * its wildcards escaped.
 */
final class ManagedProfileList
{
    public const PER_PAGE = 20;

    /** Status filter value for profiles deleted within the recovery window. */
    public const DELETED = 'deleted';

    /** Assignee filter value for "nobody". */
    public const UNASSIGNED = 'none';

    public function __construct(private readonly ManagedProfileScope $scope) {}

    /** @return LengthAwarePaginator<int, Profile> */
    public function page(BrokerStaff $staff, string $status, string $search, string $assignee): LengthAwarePaginator
    {
        $deleted = $status === self::DELETED;
        $query = $this->scope->query($staff, withTrashed: $deleted)
            ->when($deleted, fn (Builder $q) => $q->whereNotNull('profiles.deleted_at')
                ->where('profiles.deleted_at', '>=', now()->subDays((int) config('oppam.broker.managed_restore_days'))))
            ->when(! $deleted && ProfileStatus::tryFrom($status) !== null, fn (Builder $q) => $q->where('profiles.status', $status));

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('profiles.code', 'like', $like)
                ->orWhereRaw("CONCAT(profiles.first_name, ' ', COALESCE(profiles.last_name, '')) LIKE ?", [$like]));
        }

        if ($assignee === self::UNASSIGNED) {
            $query->whereNull('profiles.assigned_staff_id');
        } elseif ($assignee !== '') {
            $query->where('profiles.assigned_staff_id', $assignee);
        }

        return $query
            ->select(['profiles.id', 'profiles.code', 'profiles.first_name', 'profiles.last_name', 'profiles.gender', 'profiles.dob',
                'profiles.status', 'profiles.assigned_staff_id', 'profiles.completeness', 'profiles.updated_at', 'profiles.deleted_at',
                'profiles.owner_type', 'profiles.managed_by_broker_id', 'profiles.claimed_at'])
            ->with(['assignedStaff:id,broker_id,user_id,role', 'assignedStaff.user:id,phone', 'assignedStaff.broker:id,name'])
            ->orderByDesc('profiles.updated_at')->orderByDesc('profiles.id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * B.4 "Managed profiles" widget: total, draft, in review, active, rejected — the caller's scope —
     * and how many of the bureau's clients have claimed their profile (P7.4: once claimed it is out
     * of every scope; the bureau keeps only the count, via the referral attribution `broker_id`).
     *
     * @return array<string, int>
     */
    public function counts(BrokerStaff $staff): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = $this->scope->query($staff)->toBase()
            ->selectRaw('profiles.status as status, count(*) as total')
            ->groupBy('profiles.status')
            ->pluck('total', 'status')->map(fn ($n): int => (int) $n)->all();

        return [
            'total' => array_sum($byStatus),
            'draft' => $byStatus[ProfileStatus::Draft->value] ?? 0,
            'review' => $byStatus[ProfileStatus::PendingReview->value] ?? 0,
            'active' => $byStatus[ProfileStatus::Active->value] ?? 0,
            'rejected' => $byStatus[ProfileStatus::Rejected->value] ?? 0,
            'claimed' => Profile::query()->where('broker_id', $staff->broker_id)->whereNotNull('claimed_at')->count(),
        ];
    }

    /** B.4 "Client inbox": interests waiting for an answer on the clients in the caller's scope. */
    public function pendingInterests(BrokerStaff $staff): int
    {
        return Interest::query()
            ->whereIn('to_profile_id', $this->scope->query($staff)->select('profiles.id'))
            ->where('status', InterestStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * B.4 "Moderation feed": the latest A04 decisions on profiles in the caller's scope.
     *
     * @return Collection<int, ModerationItem>
     */
    public function latestDecisions(BrokerStaff $staff, int $limit = 5): Collection
    {
        return ModerationItem::query()
            ->whereIn('profile_id', $this->scope->query($staff)->select('profiles.id'))
            ->where('type', ModerationItemType::ProfileNew->value)
            ->whereNotNull('decided_by_admin_id')          // moderator decisions, not system closures
            ->with('profile:id,code')
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'profile_id', 'type', 'status', 'decided_at']);
    }

    /**
     * B.4 "Follow-ups due today": open follow-ups due today or earlier (IST) on profiles in scope.
     *
     * @return Collection<int, BrokerClientNote>
     */
    public function dueFollowUps(BrokerStaff $staff, int $limit = 5): Collection
    {
        return BrokerClientNote::query()
            ->where('broker_id', $staff->broker_id)
            ->whereNull('follow_up_done_at')
            ->where('follow_up_on', '<=', now(config('oppam.display_timezone'))->format('Y-m-d'))
            ->whereIn('profile_id', $this->scope->query($staff)->select('profiles.id'))
            ->with('profile:id,code')
            ->orderBy('follow_up_on')->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Who a profile can be assigned to: the bureau's ACTIVE logins.
     *
     * @return Collection<int, BrokerStaff>
     */
    public function assignees(BrokerStaff $staff): Collection
    {
        return BrokerStaff::query()
            ->where('broker_id', $staff->broker_id)
            ->where('status', BureauStaffStatus::Active)
            ->with(['user:id,phone', 'broker:id,name'])
            ->orderBy('role')->orderBy('created_at')
            ->get();
    }
}
