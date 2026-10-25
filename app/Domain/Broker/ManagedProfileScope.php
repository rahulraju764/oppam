<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\BureauPermission;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Which managed profiles a bureau login may touch (§11A B.6, R-M13-6 / R-M13-18 / R-M13-20): the
 * caller's own bureau (broker id from the staff row, never from input), BROKER_MANAGED and not
 * claimed (R-M13-16), and for "assigned only" staff just the profiles assigned to them. Every
 * bureau screen and Action finds profiles through here; anything outside answers 404.
 */
final class ManagedProfileScope
{
    public function __construct(private readonly BureauGate $gate) {}

    /** @return Builder<Profile> */
    public function query(BrokerStaff $staff, bool $withTrashed = false): Builder
    {
        $query = $withTrashed ? Profile::withTrashed() : Profile::query();

        return $query
            ->where('profiles.managed_by_broker_id', $staff->broker_id)
            ->where('profiles.owner_type', ProfileOwnerType::BrokerManaged->value)
            ->whereNull('profiles.claimed_at')
            ->when($staff->profile_scope === ProfileScope::Assigned,
                fn (Builder $q) => $q->where('profiles.assigned_staff_id', $staff->id));
    }

    /**
     * The profile with this code inside the caller's scope, or 404.
     *
     * @throws ModelNotFoundException<Profile>
     */
    public function find(BrokerStaff $staff, string $code, bool $withTrashed = false): Profile
    {
        return $this->query($staff, $withTrashed)->where('profiles.code', $code)->firstOrFail();
    }

    public function covers(BrokerStaff $staff, Profile $profile): bool
    {
        return $profile->isBrokerManaged()
            && $profile->managed_by_broker_id === $staff->broker_id
            && ($staff->profile_scope !== ProfileScope::Assigned || $profile->assigned_staff_id === $staff->id);
    }

    /** May this login do $permission on this profile now (BureauGate + scope)? Used by ProfilePolicy. */
    public function allows(?User $user, Profile $profile, BureauPermission $permission): bool
    {
        if (! $profile->isBrokerManaged() || ! $this->gate->allows($user, $permission)) {
            return false;
        }

        $staff = $this->gate->staff($user);

        return $staff !== null && $this->covers($staff, $profile);
    }
}
