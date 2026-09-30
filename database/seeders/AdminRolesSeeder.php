<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin permissions and the seven seed roles (PRD §8.4) from config/admin-permissions.php.
 * Safe in every environment:
 * - every permission key is created if missing (new modules add keys);
 * - a role gets its default permissions only when the role is first created — afterwards the role
 *   editor owns it, and a re-seed never overwrites those edits;
 * - super_admin is synced to every permission each run (it is fixed and holds everything).
 */
final class AdminRolesSeeder extends Seeder
{
    private const GUARD = 'admin';

    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        /** @var array<string, list<string>> $groups */
        $groups = config('admin-permissions.permissions');
        $all = array_merge(...array_values($groups));

        foreach ($all as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        /** @var array<string, list<string>|string> $roles */
        $roles = config('admin-permissions.roles');

        foreach ($roles as $roleName => $grants) {
            $existing = Role::query()->where('name', $roleName)->where('guard_name', self::GUARD)->first();
            $role = $existing ?? Role::create(['name' => $roleName, 'guard_name' => self::GUARD]);

            if ($roleName === 'super_admin') {
                $role->syncPermissions($all);
            } elseif ($existing === null) {
                $role->syncPermissions($this->defaultGrants($grants, $all));
            }
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * @param  list<string>|string  $grants  explicit keys, or 'views' = every *.view key except the sensitive ones
     * @param  list<string>  $all
     * @return list<string>
     */
    private function defaultGrants(array|string $grants, array $all): array
    {
        if ($grants !== 'views') {
            return (array) $grants;
        }

        /** @var list<string> $sensitive */
        $sensitive = config('admin-permissions.sensitive');

        return array_values(array_filter(
            $all,
            fn (string $name): bool => str_ends_with($name, '.view') && ! in_array($name, $sensitive, true),
        ));
    }
}
