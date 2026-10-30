<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Staff;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Actions\Broker\Staff\DeactivateStaff;
use App\Exceptions\Broker\StaffRefused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 Staff tab "deactivate" (§11A B.12; admins can't add staff, R-M13-24): the same deactivation
 * the Owner does (DeactivateStaff::deactivate — seat freed, clients unassigned, sessions ended,
 * trusted devices removed, ForceLogout live), found by staff code inside the given bureau only
 * (another bureau's code → 404). The Owner's own row is refused. Needs `brokers.staff.edit`; typed
 * reason, audited as the admin (`brokers.staff_deactivated`).
 */
final class DeactivateBureauStaff
{
    use ManagesBureaus;

    public function __construct(private readonly DeactivateStaff $deactivation) {}

    /**
     * @return int clients returned to the bureau's unassigned pool
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerStaff>
     * @throws StaffRefused the Owner's row, already deactivated
     */
    public function handle(AdminUser $admin, Broker $broker, string $staffCode, string $reason): int
    {
        $this->authorizeFor($admin, 'brokers.staff.edit');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $broker, $staffCode, $reason): int {
            $staff = BrokerStaff::query()->where('broker_id', $broker->id)->where('code', strtoupper(trim($staffCode)))->lockForUpdate()->firstOrFail();

            return $this->deactivation->deactivate($staff, $admin, 'brokers.staff_deactivated', $reason);
        });
    }
}
