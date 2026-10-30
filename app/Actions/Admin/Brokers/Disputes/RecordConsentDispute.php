<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Disputes;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Domain\Safety\AbuseCases;
use App\Enums\CasePriority;
use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Enums\ModerationHold;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 records a client's "I never agreed to this profile" complaint (§11A B.12 Consent disputes,
 * R-A07-10, B.18 DPDP; decisions 2026-10-29 (4): recorded by an admin). In one transaction, with the
 * bureau and the profile locked:
 *  - the profile must be one this bureau manages now (unclaimed, not deleted) — else refused;
 *  - one open dispute per profile;
 *  - the profile is taken out of sight at once: SUSPENDED (an admin-only status — the bureau's own
 *    "activate" can't undo it), the status it had is kept on the dispute for a dismissal;
 *  - its waiting A04 items are held (ModerationHold::ConsentDispute — out of the queues, so nobody
 *    decides or closes them meanwhile; a dismissal puts exactly those back);
 *  - an A10 case is opened (or joined) at P1 — no reporter;
 *  - the dispute is OPEN until DecideConsentDispute.
 * Needs `brokers.edit`; the note (5–500) is kept; audited.
 */
final class RecordConsentDispute
{
    use ChangesMemberState, ManagesBureaus {
        ManagesBureaus::validatedReason insteadof ChangesMemberState;
    }

    public function __construct(
        private readonly AbuseCases $cases,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict not this bureau's managed profile, or a dispute is already open
     */
    public function handle(AdminUser $admin, Broker $broker, string $profileCode, DisputeSource $source, string $note): BrokerConsentDispute
    {
        $this->authorizeFor($admin, 'brokers.edit');
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => __('Describe the complaint in 5 to 500 characters.')]);
        }
        $code = strtoupper(trim($profileCode));

        return DB::transaction(function () use ($admin, $broker, $code, $source, $note): BrokerConsentDispute {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            $profile = Profile::query()->where('code', $code)->where('managed_by_broker_id', $locked->id)
                ->where('owner_type', ProfileOwnerType::BrokerManaged->value)->lockForUpdate()->first();
            if ($profile === null || $profile->status === ProfileStatus::Deleted) {
                throw BrokerStateConflict::notManagedProfile();
            }
            if (BrokerConsentDispute::query()->where('profile_id', $profile->id)->where('status', ConsentDisputeStatus::Open)->exists()) {
                throw BrokerStateConflict::disputeAlreadyOpen();
            }

            $before = $profile->status;
            if ($before !== ProfileStatus::Suspended) {
                $profile->forceFill(['previous_status' => $before, 'status' => ProfileStatus::Suspended])->save();
            }
            $held = $this->holdModerationItems($profile, ModerationHold::ConsentDispute);

            $case = $this->cases->openForProfile($profile->id, CasePriority::P1);

            $dispute = new BrokerConsentDispute;
            $dispute->forceFill([
                'broker_id' => $locked->id,
                'profile_id' => $profile->id,
                'abuse_case_id' => $case->id,
                'source' => $source,
                'note' => $note,
                'status' => ConsentDisputeStatus::Open,
                'profile_status_before' => $before,
                'recorded_by_admin_id' => $admin->id,
            ])->save();

            $this->audit->record('brokers.dispute_recorded', $dispute, [], [
                'dispute' => $dispute->code, 'broker' => $locked->code, 'profile' => $profile->code, 'case' => $case->code,
                'source' => $source->value, 'profile_status' => $before->value, 'moderation_items_held' => $held,
            ], reason: $note, actor: $admin, subjectLabel: $dispute->code);

            return $dispute;
        });
    }
}
