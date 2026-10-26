<?php

declare(strict_types=1);

namespace App\Queries\Broker;

use App\Domain\Broker\StaffSeats;
use App\Enums\BureauRole;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\Profile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Owner's staff screens (§11A B.8): seats (used / limit, R-M13-25), the bureau's logins with
 * their assigned-client counts, open invitations, and one login's assigned clients. Always one
 * bureau — the caller's — never an id from input.
 */
final class StaffDirectory
{
    public function __construct(private readonly StaffSeats $seats) {}

    /** @return array{used: int, limit: int, open_invitations: int, free: int} */
    public function seats(Broker $broker): array
    {
        return [
            'used' => $this->seats->used($broker),
            'limit' => $this->seats->limit($broker),
            'open_invitations' => $this->seats->openInvitations($broker),
            'free' => $this->seats->free($broker),
        ];
    }

    /** @return Collection<int, BrokerStaff> Owner first, then by status and join date; with `assigned_count` */
    public function staff(Broker $broker): Collection
    {
        return BrokerStaff::query()->where('broker_id', $broker->id)
            ->with(['user:id,phone', 'broker:id,name'])
            ->withCount(['assignedProfiles as assigned_count'])
            ->orderByRaw('case when role = ? then 0 else 1 end', [BureauRole::Owner->value])
            ->orderBy('status')->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, BrokerStaffInvitation> */
    public function openInvitations(Broker $broker): Collection
    {
        return BrokerStaffInvitation::query()->where('broker_id', $broker->id)
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    /** The login with this code in this bureau, or 404. */
    public function find(Broker $broker, string $code): BrokerStaff
    {
        return BrokerStaff::query()->where('broker_id', $broker->id)->where('code', $code)
            ->with(['user:id,phone', 'broker:id,name'])
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, Profile> the clients assigned to this login (unclaimed, managed by the bureau) */
    public function assignedProfiles(BrokerStaff $staff): LengthAwarePaginator
    {
        return Profile::query()->where('managed_by_broker_id', $staff->broker_id)->where('assigned_staff_id', $staff->id)
            ->whereNull('claimed_at')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(10, ['id', 'code', 'first_name', 'last_name', 'status']);
    }
}
