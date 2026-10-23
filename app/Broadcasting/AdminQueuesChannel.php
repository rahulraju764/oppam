<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\AdminUser;

/**
 * `admin.queues` (PRD §9.3): live queue counts. Any ACTIVE, unlocked admin with 2FA confirmed who holds
 * a queue permission (AdminUser::watchesQueues: moderation, verification, chat flags, broker KYC, A10
 * safety cases and support tickets). Re-checked against the database on every join.
 */
final class AdminQueuesChannel
{
    public function join(mixed $admin): bool
    {
        if (! $admin instanceof AdminUser) {
            return false;
        }

        $admin = $admin->fresh();

        return $admin instanceof AdminUser
            && $admin->isActive()
            && ! $admin->isLocked()
            && $admin->hasConfirmedTwoFactor()
            && $admin->watchesQueues();
    }
}
