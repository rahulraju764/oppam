<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;

/**
 * A bureau's staff seats (§11A B.8, R-M13-25; decisions 2026-10-26): `brokers.staff_seat_limit`
 * (default 5, A07 per bureau). The Owner uses no seat; ACTIVE staff and unexpired, unaccepted
 * invitations do — a deactivated login or an expired / cancelled invitation frees its seat. A
 * lowered limit never deactivates anyone (R-A07-11): new invites simply wait for a free seat.
 */
final class StaffSeats
{
    public function limit(Broker $broker): int
    {
        return $broker->staff_seat_limit;
    }

    public function activeStaff(Broker $broker): int
    {
        return BrokerStaff::query()->where('broker_id', $broker->id)
            ->where('role', '!=', BureauRole::Owner->value)
            ->where('status', BureauStaffStatus::Active->value)
            ->count();
    }

    public function openInvitations(Broker $broker): int
    {
        return BrokerStaffInvitation::query()->where('broker_id', $broker->id)
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->count();
    }

    public function used(Broker $broker): int
    {
        return $this->activeStaff($broker) + $this->openInvitations($broker);
    }

    public function free(Broker $broker): int
    {
        return max(0, $this->limit($broker) - $this->used($broker));
    }
}
