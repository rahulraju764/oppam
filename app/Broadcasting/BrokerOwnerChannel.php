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
 * `broker-owner.{broker}` (§11A B.15): Owner-only bureau events — commission earned (B.10), later
 * KYC and payout updates. Re-checked against the database on every auth request: an ACTIVE bureau
 * login that is that bureau's ACTIVE Owner, on a bureau that is still open (not rejected or
 * deactivated). Staff of any other role, and every other bureau, are refused.
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
            ->whereHas('broker', fn ($query) => $query->whereNotIn('status', [BrokerStatus::Rejected, BrokerStatus::Deactivated]))
            ->exists();
    }
}
