<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/**
 * An admin action refused by a staff/roles guardrail (PRD §8.4, A01): granting what you don't
 * hold, removing the last super admin, acting on yourself where that is not allowed…
 * The message is shown to the admin as-is.
 */
final class GuardrailViolation extends RuntimeException
{
    public static function cannotGrantUnheld(string $permission): self
    {
        return new self(__('You can’t grant or remove “:permission” because you don’t hold it yourself.', ['permission' => $permission]));
    }

    public static function lastSuperAdmin(): self
    {
        return new self(__('This is the last active super admin. Make someone else a super admin first.'));
    }

    public static function notOnYourself(): self
    {
        return new self(__('You can’t do this to your own account.'));
    }

    public static function superAdminRoleIsFixed(): self
    {
        return new self(__('The super admin role always has every permission and can’t be edited.'));
    }

    public static function alreadyAdmin(): self
    {
        return new self(__('An admin account with this email already exists.'));
    }
}
