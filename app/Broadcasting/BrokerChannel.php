<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\BrokerStatus;
use App\Enums\BureauStaffStatus;
use App\Enums\UserRole;
use App\Models\BrokerStaff;
use App\Models\User;

/**
 * `broker.{broker}` (§11A B.15): bureau-wide events — managed-profile moderation (P7.3), later
 * imports and client inbox. Re-checked against the database on every auth request: an active
 * BROKER login whose broker_staff row is ACTIVE and belongs to that bureau, on a bureau that is
 * still open (not rejected or deactivated). Every other bureau, members and guests are refused.
 * Payloads carry profile codes only; pages re-read their own scoped data.
 */
final class BrokerChannel
{
    public function join(mixed $user, string $broker): bool
    {
        if (! $user instanceof User || $user->role !== UserRole::Broker || ! $user->isActive()) {
            return false;
        }

        return BrokerStaff::query()
            ->where('user_id', $user->id)
            ->where('broker_id', $broker)
            ->where('status', BureauStaffStatus::Active)
            ->whereHas('broker', fn ($query) => $query->whereNotIn('status', [BrokerStatus::Rejected, BrokerStatus::Deactivated]))
            ->exists();
    }
}
