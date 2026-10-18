<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\AdminUser;

/**
 * `admin.queues` (PRD §9.3): live queue counts. Any ACTIVE, unlocked admin with 2FA confirmed who holds
 * `moderation.view`, `verification.queue.view` or `chat.view_flagged` (A13). Re-checked against the database on every join.
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
            && ($admin->can('moderation.view') || $admin->can('verification.queue.view') || $admin->can('chat.view_flagged'));
    }
}
