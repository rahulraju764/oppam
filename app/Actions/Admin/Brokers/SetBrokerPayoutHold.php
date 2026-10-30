<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hold / release a bureau's payouts (§11A B.12 fraud response "flag; hold commission"): a held
 * bureau keeps earning and keeps working, but is left out of new payout runs and its open lines
 * can't be marked paid until it is released. Needs `brokers.payout` and a reason; audited.
 */
final class SetBrokerPayoutHold
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict
     */
    public function handle(AdminUser $admin, Broker $broker, bool $hold, string $reason): Broker
    {
        $this->authorizeFor($admin, 'brokers.payout');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $broker, $hold, $reason): Broker {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();

            if ($hold && $locked->payouts_held_at !== null) {
                throw BrokerStateConflict::alreadyHeld();
            }
            if (! $hold && $locked->payouts_held_at === null) {
                throw BrokerStateConflict::notHeld();
            }

            $locked->forceFill(['payouts_held_at' => $hold ? now() : null, 'payouts_hold_reason' => $hold ? $reason : null])->save();

            $this->audit->record($hold ? 'brokers.payouts_held' : 'brokers.payouts_released', $locked,
                ['payouts_held' => ! $hold], ['payouts_held' => $hold], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }
}
