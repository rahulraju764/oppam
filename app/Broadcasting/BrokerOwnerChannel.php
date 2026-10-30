<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\UserRole;
use App\Models\BrokerStaff;
use App\Models\User;

/**
 * `broker-owner.{broker}` (§11A B.15): Owner-only bureau events — commission earned and payout
 * paid (B.10), suspension. Re-checked against the database on every auth request: an ACTIVE bureau
 * login that is that bureau's ACTIVE Owner, on an ACTIVE bureau (P7.8: a suspended or KYC-pending
 * bureau's Owner has no live money events to receive — the suspension itself reaches open tabs
 * through ForceLogout). Staff of any other role, and every other bureau, are refused.
 */
final class BrokerOwnerChannel
{
    public function join(mixed $user, string $broker): bool
    {
        if (! $user instanceof User || $user->role !== UserRole::Broker || ! $user->isActive()) {
            return false;
        }

        return BrokerStaff::query()
            ->where('user_id', $user->id)
            ->where('broker_id', $broker)
            ->where('role', BureauRole::Owner)
            ->where('status', BureauStaffStatus::Active)
            ->whereHas('broker', fn ($query) => $query->where('status', BrokerStatus::Active))
            ->exists();
    }
}
