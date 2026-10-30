<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Staff;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\BureauStaffStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Realtime\LiveSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A07 Staff tab "reset device / OTP" (§11A B.12, B.18 "OTP on new devices for Owner/Manager"): for
 * a lost or shared phone — every trusted device of the login is forgotten and every session ends
 * (session epoch + 1, remember token rotated, ForceLogout live), so the next sign-in asks for the
 * OTP again. The login stays ACTIVE. Also for the Owner (support-assisted, R-M13-24). Found by staff
 * code inside the given bureau only (another bureau's → 404). Needs `brokers.staff.edit`; typed
 * reason, audited.
 */
final class ResetStaffDevices
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int trusted devices removed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerStaff>
     * @throws BrokerStateConflict the login isn't active
     */
    public function handle(AdminUser $admin, Broker $broker, string $staffCode, string $reason): int
    {
        $this->authorizeFor($admin, 'brokers.staff.edit');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $broker, $staffCode, $reason): int {
            $staff = BrokerStaff::query()->where('broker_id', $broker->id)->where('code', strtoupper(trim($staffCode)))->lockForUpdate()->firstOrFail();
            if ($staff->status !== BureauStaffStatus::Active) {
                throw BrokerStateConflict::staffNotActive();
            }

            $login = User::query()->whereKey($staff->user_id)->lockForUpdate()->firstOrFail();
            $login->forceFill(['session_epoch' => $login->session_epoch + 1, 'remember_token' => Str::random(60)])->save();
            $removed = TrustedDevice::query()->where('user_id', $login->id)->delete();
            LiveSignal::afterCommit(new ForceLogout((string) $login->id));

            $this->audit->record('brokers.staff_devices_reset', $staff, [], ['devices_removed' => $removed],
                reason: $reason, actor: $admin, subjectLabel: $staff->code);

            return $removed;
        });
    }
}
