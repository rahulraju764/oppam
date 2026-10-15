<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\AdminUser;

/**
 * `admin.dashboard` (PRD §9.3): live KPI ticks. Any ACTIVE, unlocked admin with 2FA confirmed who
 * holds `dashboard.view`. Re-checked against the database on every join.
 */
final class AdminDashboardChannel
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
            && $admin->can('dashboard.view');
    }
}
