<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Signals;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerFraudSignal;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 resolves an open fraud / quality signal of a bureau after reviewing it (§11A B.12): the note
 * is kept on the signal and in the audit log; the next scan opens a new signal only if the pattern
 * is found again. Resolving never releases a payout hold — that is its own decision (Release
 * payouts, `brokers.payout`). Found by id within the bureau (another bureau's → 404). Needs
 * `brokers.edit`.
 */
final class ResolveFraudSignal
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerFraudSignal>
     * @throws BrokerStateConflict already resolved
     */
    public function handle(AdminUser $admin, Broker $broker, string $signalId, string $note): BrokerFraudSignal
    {
        $this->authorizeFor($admin, 'brokers.edit');
        $note = $this->validatedReason($note);

        return DB::transaction(function () use ($admin, $broker, $signalId, $note): BrokerFraudSignal {
            $signal = BrokerFraudSignal::query()->where('broker_id', $broker->id)->whereKey($signalId)->lockForUpdate()->firstOrFail();
            if ($signal->resolved_at !== null) {
                throw BrokerStateConflict::signalResolved();
            }

            $signal->forceFill(['resolved_at' => now(), 'resolved_by_admin_id' => $admin->id, 'resolution_note' => $note, 'open_key' => null])->save();
            $this->audit->record('brokers.signal_resolved', $broker, after: ['signal' => $signal->type->value], reason: $note, actor: $admin, subjectLabel: $broker->code);

            return $signal;
        });
    }
}
