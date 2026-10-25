<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bureau's status actions on a managed profile (§11A B.6 "activate / deactivate, soft-delete
 * (30-day recovery)"), each with its B.2 permission.
 */
enum ManagedProfileStatusChange: string
{
    case Deactivate = 'deactivate';
    case Activate = 'activate';
    case Delete = 'delete';
    case Restore = 'restore';

    public function permission(): BureauPermission
    {
        return match ($this) {
            self::Deactivate => BureauPermission::ProfilesDeactivate,
            self::Activate => BureauPermission::ProfilesActivate,
            self::Delete, self::Restore => BureauPermission::ProfilesDelete,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Deactivate => __('Deactivate'),
            self::Activate => __('Activate'),
            self::Delete => __('Delete'),
            self::Restore => __('Restore'),
        };
    }

    public function done(): string
    {
        return match ($this) {
            self::Deactivate => __('The profile is deactivated and no longer appears in search.'),
            self::Activate => __('The profile is active again and appears in search.'),
            self::Delete => __('The profile is deleted. You can restore it within :days days.', ['days' => (int) config('oppam.broker.managed_restore_days')]),
            self::Restore => __('The profile is restored.'),
        };
    }
}
