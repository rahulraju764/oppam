<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The base of every journey audience (P8.3b2): members (role MEMBER) with an ACTIVE, not-deleted
 * account and a not-deleted profile of their own — never a profile a bureau still runs (unclaimed:
 * `managed_by_broker_id` set), whose family hasn't signed up. The same account rules as A14
 * campaigns (SegmentQuery); each audience adds the profile status it needs.
 */
final class ReachableMembers
{
    public static function query(): Builder
    {
        return DB::table('profiles')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('users.role', UserRole::Member->value)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->whereNull('profiles.deleted_at')
            ->whereNull('profiles.managed_by_broker_id');
    }
}
