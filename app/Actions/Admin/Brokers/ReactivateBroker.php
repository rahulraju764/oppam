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
 * A07 "reactivate": a SUSPENDED bureau is ACTIVE again with the same team (staff rows were left as
 * they were); its logins sign in again. KYC is not re-done here — if it was revoked meanwhile the
 * KYC gate still refuses create / import / export. Needs `brokers.edit` and a reason; audited.
 */
final class ReactivateBroker
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

        return $this->changeStatus($this->audit, $admin, $broker, BrokerStatus::Active, $reason, 'brokers.reactivated',
            function (Broker $locked): void {
                if ($locked->status !== BrokerStatus::Suspended) {
                    throw BrokerStateConflict::notSuspended();
                }
            });
    }
}
