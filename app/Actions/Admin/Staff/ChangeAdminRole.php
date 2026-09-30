<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Domain\Admin\StaffGuardrails;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Give a staff member a different role (A01). Guardrails: you can't change your own role, you can
 * only grant a role whose permissions you hold, and the last active super admin can't be demoted.
 * Staff have exactly one role.
 */
final class ChangeAdminRole
{
    public function __construct(
        private readonly StaffGuardrails $guardrails,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $actor, AdminUser $target, string $roleName): void
    {
        Gate::forUser($actor)->authorize('staff.edit');
        $this->guardrails->assertNotSelf($actor, $target);

        $role = $this->guardrails->role($roleName);
        $this->guardrails->assertCanGrantRole($actor, $role);

        // Taking a role away is also "granting" in reverse: you must hold what they lose.
        $this->guardrails->assertOutranks($actor, $target);

        DB::transaction(function () use ($actor, $target, $role): void {
            if ($role->name !== StaffGuardrails::SUPER_ADMIN) {
                $this->guardrails->assertNotLastSuperAdmin($target);
            }

            $before = ['roles' => $target->getRoleNames()->all()];
            $target->syncRoles([$role]);

            $this->audit->record('staff.role_changed', $target, $before, ['roles' => [$role->name]], actor: $actor, subjectLabel: $target->email);
        });
    }
}
