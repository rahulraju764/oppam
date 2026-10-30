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
 * A07 "suspend" (§11A B.3, R-A07-8, R-M13-8): an ACTIVE bureau becomes SUSPENDED — Owner and every
 * staff login are signed out at once and refused until reactivation; historical attribution stays,
 * nothing new is earned, created, imported or exported, and the bureau is left out of payout runs.
 * Needs `brokers.edit` and a reason; audited.
 */
final class SuspendBroker
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

        return $this->changeStatus($this->audit, $admin, $broker, BrokerStatus::Suspended, $reason, 'brokers.suspended',
            function (Broker $locked): void {
                if ($locked->status !== BrokerStatus::Active) {
                    throw BrokerStateConflict::notActive();
                }
            });
    }
}
