<?php

declare(strict_types=1);

namespace App\Actions\Broker\Staff;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileScope;
use App\Exceptions\Broker\StaffRefused;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Owner's switch-offs and profile scope for one staff member (§11A B.2 "the Owner can switch
 * individual permissions off, never on beyond the role's ceiling", B.8; R-M13-19 / R-M13-24):
 * every key must be in the staff member's role ceiling (an unknown key or one above the role is a
 * 422 — the browser can't widen anything); the dashboard can't be switched off (every login needs
 * a landing page); the Owner's own row is refused. BureauGate reads permissions fresh, so the
 * change applies to that login's very next request. Found by staff code inside the caller's bureau
 * (else 404); row locked; audited with before / after.
 */
final class UpdateStaffPermissions
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Permissions an Owner may switch off for this role.
     *
     * @return list<BureauPermission>
     */
    public static function switchable(BrokerStaff $staff): array
    {
        return array_values(array_filter(BureauPermission::ceiling($staff->role),
            fn (BureauPermission $p): bool => $p !== BureauPermission::DashboardView));
    }

    /**
     * @param  list<mixed>  $disabled  permission keys to switch off
     *
     * @throws HttpException 403 without `staff.manage`
     * @throws ModelNotFoundException<BrokerStaff>
     * @throws StaffRefused the Owner's own row
     * @throws ValidationException a key outside the role's ceiling, an unknown scope
     */
    public function handle(User $owner, string $staffCode, array $disabled, string $scope): BrokerStaff
    {
        $caller = $this->gate->authorize($owner, BureauPermission::StaffManage);

        return DB::transaction(function () use ($owner, $caller, $staffCode, $disabled, $scope): BrokerStaff {
            $staff = BrokerStaff::query()->where('broker_id', $caller->broker_id)->where('code', $staffCode)->lockForUpdate()->firstOrFail();

            if ($staff->isOwner()) {
                throw StaffRefused::ownerProtected();
            }

            if ($staff->status === BureauStaffStatus::Deactivated) {
                throw StaffRefused::alreadyDeactivated();
            }

            $allowed = array_map(fn (BureauPermission $p): string => $p->value, self::switchable($staff));
            $keys = array_values(array_unique(array_filter($disabled, 'is_string')));

            if (count($keys) !== count($disabled) || array_diff($keys, $allowed) !== []) {
                throw ValidationException::withMessages(['disabled' => __('Only permissions of this staff member’s role can be switched off.')]);
            }

            $newScope = ProfileScope::tryFrom($scope) ?? throw ValidationException::withMessages(['scope' => __('Choose which profiles this staff member can see.')]);
            if ($staff->role === BureauRole::Telecaller && $newScope !== ProfileScope::Assigned) {
                throw ValidationException::withMessages(['scope' => InviteStaff::telecallerScopeMessage()]);   // B.2 ceiling
            }

            sort($keys);
            $before = ['disabled' => $staff->disabled_permissions ?? [], 'scope' => $staff->profile_scope->value];
            $staff->forceFill(['disabled_permissions' => $keys === [] ? null : $keys, 'profile_scope' => $newScope])->save();

            $this->audit->record('broker.staff_permissions_changed', $staff, $before,
                ['disabled' => $keys, 'scope' => $newScope->value], actor: $owner, subjectLabel: $staff->code);

            return $staff;
        });
    }
}
