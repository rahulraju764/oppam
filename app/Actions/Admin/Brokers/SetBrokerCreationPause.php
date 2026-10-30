<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Notifications\Broker\BureauCreationPaused;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pause or lift a bureau's create / import rights (R-A07-10 "suspends create/import rights pending
 * review"; decisions 2026-10-29: a flag, not a suspension). Paused automatically by
 * DecideConsentDispute when upheld disputes reach the limit, or here by an admin; lifted here only,
 * after the review. While paused BureauGate refuses `profiles.create` and `profiles.import`
 * (403 BROKER_CREATION_PAUSED); logins, the client inbox, notes, exports and payouts keep working.
 * Needs `brokers.edit`; typed reason, audited; the Owner is notified (BureauCreationPaused).
 */
final class SetBrokerCreationPause
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict lifting a pause that isn't there
     */
    public function handle(AdminUser $admin, Broker $broker, bool $paused, string $reason): Broker
    {
        $this->authorizeFor($admin, 'brokers.edit');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $broker, $paused, $reason): Broker {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            if (! $paused && $locked->creation_paused_at === null) {
                throw BrokerStateConflict::creationNotPaused();
            }

            $this->apply($locked, $paused, $reason, $admin);

            return $locked;
        });
    }

    /** The change itself, on a bureau row the caller has locked; false when it already was so. */
    public function apply(Broker $locked, bool $paused, string $reason, ?AdminUser $admin = null): bool
    {
        if (($locked->creation_paused_at !== null) === $paused) {
            return false;
        }

        $locked->forceFill(['creation_paused_at' => $paused ? now() : null, 'creation_paused_reason' => $paused ? $reason : null])->save();
        $this->audit->record($paused ? 'brokers.creation_paused' : 'brokers.creation_resumed', $locked, ['creation_paused' => ! $paused],
            ['creation_paused' => $paused], reason: $reason, actor: $admin, subjectLabel: $locked->code);

        // The Owner is told after the commit (never the reason).
        $brokerId = $locked->id;
        DB::afterCommit(static function () use ($brokerId, $paused): void {
            BrokerStaff::query()->where('broker_id', $brokerId)->where('role', BureauRole::Owner)->where('status', BureauStaffStatus::Active)
                ->with('user')->first()?->user?->notify(new BureauCreationPaused($paused));
        });

        return true;
    }
}
