<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

use App\Domain\Admin\StaffGuardrails;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role editor save (A01). The super_admin role is fixed. Every permission added OR removed must
 * be one the editor holds ("can't grant what you don't hold"). Only known admin-guard permission
 * keys are accepted — a tampered request can't invent one.
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly StaffGuardrails $guardrails,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $permissions */
    public function handle(AdminUser $actor, string $roleName, array $permissions): Role
    {
        Gate::forUser($actor)->authorize('roles.edit');

        $role = $this->guardrails->role($roleName);
        $this->guardrails->assertEditableRole($role);

        $requested = array_values(array_unique($permissions));
        $known = Permission::query()->where('guard_name', 'admin')->whereIn('name', $requested)->pluck('name')->all();

        if (count($known) !== count($requested)) {
            throw ValidationException::withMessages(['permissions' => __('Unknown permission.')]);
        }

        $current = $role->permissions->pluck('name')->all();
        $changed = array_merge(array_diff($requested, $current), array_diff($current, $requested));
        $this->guardrails->assertHoldsAll($actor, $changed);

        DB::transaction(function () use ($actor, $role, $current, $requested): void {
            $role->syncPermissions($requested);
            $this->audit->record('roles.updated', $role, ['permissions' => $current], ['permissions' => $requested], actor: $actor, subjectLabel: $role->name);
        });

        return $role;
    }
}
