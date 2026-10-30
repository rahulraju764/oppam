<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ChangesBureauStatus;
use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\BrokerStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * A07 "deactivate" (§11A B.3, R-A07-8, B.21): an ACTIVE or SUSPENDED bureau is closed for good —
 * Owner and every staff login are signed out at once and can't come back (a closed bureau is not
 * reactivated in A07; support re-onboards — decisions 2026-10-29). History (referrals, managed
 * profiles, exports, payouts) is kept; nothing new is earned. Needs `brokers.edit` and a reason; audited.
 */
final class DeactivateBroker
{
    use ChangesBureauStatus, ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict
     */
    public function handle(AdminUser $admin, Broker $broker, string $reason): Broker
    {
        $this->authorizeFor($admin, 'brokers.edit');
        $reason = $this->validatedReason($reason);

        return $this->changeStatus($this->audit, $admin, $broker, BrokerStatus::Deactivated, $reason, 'brokers.deactivated',
            function (Broker $locked): void {
                if (! in_array($locked->status, [BrokerStatus::Active, BrokerStatus::Suspended], true)) {
                    throw BrokerStateConflict::cannotDeactivate();
                }
            });
    }
}
