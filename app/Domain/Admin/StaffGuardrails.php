<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Enums\AdminStatus;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminUser;
use Spatie\Permission\Models\Role;

/**
 * The staff & role guardrails of PRD §8.4 / A01, in one place so every Action applies them the
 * same way:
 * - you can't grant (or take away) a permission you don't hold yourself;
 * - the last ACTIVE super admin can't be suspended, demoted or removed;
 * - the super_admin role itself is fixed (it holds everything).
 */
final class StaffGuardrails
{
    public const SUPER_ADMIN = 'super_admin';

    /** An admin-guard role by name (404 when it doesn't exist — never trust a role name from input). */
    public function role(string $name): Role
    {
        return Role::query()->where('guard_name', 'admin')->where('name', $name)->firstOrFail();
    }

    /** @param iterable<string> $permissions */
    public function assertHoldsAll(AdminUser $actor, iterable $permissions): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        foreach ($permissions as $permission) {
            if (! $actor->hasPermissionTo($permission, 'admin')) {
                throw GuardrailViolation::cannotGrantUnheld($permission);
            }
        }
    }

    /** Granting a role = granting all of its permissions. Only a super admin can make a super admin. */
    public function assertCanGrantRole(AdminUser $actor, Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN && ! $actor->isSuperAdmin()) {
            throw GuardrailViolation::cannotGrantUnheld(self::SUPER_ADMIN);
        }

        $this->assertHoldsAll($actor, $role->permissions->pluck('name'));
    }

    /** The actor must hold every permission of every role the target has. */
    public function assertOutranks(AdminUser $actor, AdminUser $target): void
    {
        foreach ($target->getRoleNames() as $roleName) {
            $this->assertCanGrantRole($actor, $this->role($roleName));
        }
    }

    /**
     * Call inside a transaction: the other super admins are read with a lock so two concurrent
     * demotions/suspensions can't each think the other one remains. A locked account doesn't count.
     */
    public function assertNotLastSuperAdmin(AdminUser $target): void
    {
        if (! $target->isSuperAdmin()) {
            return;
        }

        // Lock EVERY super-admin row (the target's included) before counting, so two super admins
        // demoting/suspending each other at the same moment are serialised.
        $activeSuperAdminIds = AdminUser::role(self::SUPER_ADMIN, 'admin')
            ->where('status', AdminStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
            ->lockForUpdate()
            ->pluck('admin_users.id');

        $otherActiveSuperAdmins = $activeSuperAdminIds->reject(fn (string $id): bool => $id === $target->getKey())->count();

        if ($otherActiveSuperAdmins === 0) {
            throw GuardrailViolation::lastSuperAdmin();
        }
    }

    public function assertNotSelf(AdminUser $actor, AdminUser $target): void
    {
        if ($actor->is($target)) {
            throw GuardrailViolation::notOnYourself();
        }
    }

    public function assertEditableRole(Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN) {
            throw GuardrailViolation::superAdminRoleIsFixed();
        }
    }
}
