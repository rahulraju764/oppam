<?php

declare(strict_types=1);

namespace App\Actions\Broker\Staff;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\BureauStaffStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Broker\StaffRefused;
use App\Models\AdminUser;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Realtime\LiveSignal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deactivate a staff member — immediately (§11A B.8, B.15 StaffDeactivated → ForceLogout, B.21
 * "logged out of every tab within 2 s", R-M13-25): in one transaction, with the staff row and the
 * login locked,
 *  - the row becomes DEACTIVATED (its seat frees; history — notes, audit, created_by — is kept);
 *  - their assigned clients return to the Owner's unassigned pool;
 *  - every session of the login ends (session epoch + 1, remember token rotated — the next request
 *    is signed out by the session check and `broker.staff.active`) and its trusted devices go;
 *  - after the commit, ForceLogout on `App.Models.User.{id}` tells their open tabs to leave now
 *    (best effort — the server side already refuses them).
 * The Owner's own row is refused (R-M13-24). Found by staff code inside the caller's bureau (else
 * 404). Audited with the number of clients unassigned.
 */
final class DeactivateStaff
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many clients went back to the unassigned pool
     *
     * @throws HttpException 403 without `staff.manage`
     * @throws ModelNotFoundException<BrokerStaff>
     * @throws StaffRefused the Owner's own row, already deactivated
     */
    public function handle(User $owner, string $staffCode): int
    {
        $caller = $this->gate->authorize($owner, BureauPermission::StaffManage);

        return DB::transaction(function () use ($owner, $caller, $staffCode): int {
            $staff = BrokerStaff::query()->where('broker_id', $caller->broker_id)->where('code', $staffCode)->lockForUpdate()->firstOrFail();

            return $this->deactivate($staff, $owner, 'broker.staff_deactivated');
        });
    }

    /**
     * The deactivation itself, inside the caller's transaction on a staff row it has locked — shared
     * with A07 (Admin\Brokers\DeactivateBureauStaff, which authorizes and audits as the admin).
     *
     * @throws StaffRefused the Owner's own row, already deactivated
     */
    public function deactivate(BrokerStaff $staff, User|AdminUser $actor, string $auditAction, ?string $reason = null): int
    {
        if ($staff->isOwner()) {
            throw StaffRefused::ownerProtected();
        }

        if ($staff->status === BureauStaffStatus::Deactivated) {
            throw StaffRefused::alreadyDeactivated();
        }

        $login = User::query()->whereKey($staff->user_id)->lockForUpdate()->firstOrFail();
        $before = $staff->status;

        $staff->forceFill(['status' => BureauStaffStatus::Deactivated])->save();

        $unassigned = Profile::withTrashed()->where('managed_by_broker_id', $staff->broker_id)
            ->where('assigned_staff_id', $staff->id)
            ->update(['assigned_staff_id' => null, 'updated_at' => now()]);

        $login->forceFill([
            'session_epoch' => $login->session_epoch + 1,
            'remember_token' => Str::random(60),
        ])->save();
        TrustedDevice::query()->where('user_id', $login->id)->delete();

        LiveSignal::afterCommit(new ForceLogout((string) $login->id));

        $this->audit->record($auditAction, $staff, ['status' => $before->value],
            ['status' => BureauStaffStatus::Deactivated->value, 'unassigned' => $unassigned], reason: $reason, actor: $actor, subjectLabel: $staff->code);

        return $unassigned;
    }
}
