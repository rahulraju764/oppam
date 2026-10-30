<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Disputes;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Actions\Admin\Brokers\SetBrokerCreationPause;
use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ConsentDisputeStatus;
use App\Enums\ModerationHold;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 decides a consent dispute (§11A B.12, R-A07-10):
 *  - UPHELD: the client never agreed — the profile stays suspended (removing it is an A03 / A10
 *    decision). When the bureau now has `broker.dispute_upheld_limit` (3) upheld disputes decided
 *    within `broker.dispute_window_days` (90), its create / import rights are paused pending review
 *    (SetBrokerCreationPause — decisions 2026-10-29);
 *  - DISMISSED: the profile returns to the status it had, if it is still this bureau's and still
 *    suspended (an admin may have changed it since), and its held A04 items go back to the queues;
 *    a profile that was waiting for review with nothing left to review returns to DRAFT (the bureau
 *    submits it again) — never PENDING_REVIEW with no queue item. UPHELD keeps the items held.
 * Bureau, dispute and profile rows are locked; a decided dispute can't be decided again. Needs
 * `brokers.edit`; outcome note (5–500) kept; audited.
 */
final class DecideConsentDispute
{
    use ChangesMemberState, ManagesBureaus {
        ManagesBureaus::validatedReason insteadof ChangesMemberState;
    }

    public function __construct(
        private readonly SetBrokerCreationPause $pause,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{dispute: BrokerConsentDispute, creation_paused: bool}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ModelNotFoundException<BrokerConsentDispute>
     * @throws BrokerStateConflict already decided
     */
    public function handle(AdminUser $admin, string $disputeCode, bool $upheld, string $outcome): array
    {
        $this->authorizeFor($admin, 'brokers.edit');
        $outcome = trim($outcome);
        if (mb_strlen($outcome) < 5 || mb_strlen($outcome) > 500) {
            throw ValidationException::withMessages(['outcome' => __('Write the outcome in 5 to 500 characters.')]);
        }

        return DB::transaction(function () use ($admin, $disputeCode, $upheld, $outcome): array {
            $found = BrokerConsentDispute::query()->where('code', strtoupper(trim($disputeCode)))->firstOrFail();
            $broker = Broker::query()->whereKey($found->broker_id)->lockForUpdate()->firstOrFail();
            $dispute = BrokerConsentDispute::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($dispute->status !== ConsentDisputeStatus::Open) {
                throw BrokerStateConflict::disputeDecided();
            }

            $dispute->forceFill([
                'status' => $upheld ? ConsentDisputeStatus::Upheld : ConsentDisputeStatus::Dismissed,
                'decided_by_admin_id' => $admin->id,
                'decided_at' => now(),
                'outcome_note' => $outcome,
            ])->save();

            $restored = $upheld ? null : $this->restoreProfile($dispute, $broker);

            $upheldInWindow = $upheld ? BrokerConsentDispute::query()->where('broker_id', $broker->id)
                ->where('status', ConsentDisputeStatus::Upheld)
                ->where('decided_at', '>=', now()->subDays(Settings::int(SettingKey::BrokerDisputeWindowDays)))->count() : 0;
            $paused = $upheld && $upheldInWindow >= Settings::int(SettingKey::BrokerDisputeUpheldLimit)
                && $this->pause->apply($broker, true, __(':n upheld consent disputes in :days days (R-A07-10).', [
                    'n' => $upheldInWindow, 'days' => Settings::int(SettingKey::BrokerDisputeWindowDays),
                ]), $admin);

            $this->audit->record('brokers.dispute_decided', $dispute, ['status' => ConsentDisputeStatus::Open->value], array_filter([
                'status' => $dispute->status->value, 'broker' => $broker->code, 'upheld_in_window' => $upheld ? $upheldInWindow : null,
                'creation_paused' => $paused ?: null, 'profile_restored_to' => $restored?->value,
            ], fn (mixed $v): bool => $v !== null), reason: $outcome, actor: $admin, subjectLabel: $dispute->code);

            return ['dispute' => $dispute, 'creation_paused' => $paused];
        });
    }

    /** Dismissed: back to the status it had — only while it is still this bureau's and still suspended. */
    private function restoreProfile(BrokerConsentDispute $dispute, Broker $broker): ?ProfileStatus
    {
        $profile = Profile::query()->whereKey($dispute->profile_id)->where('managed_by_broker_id', $broker->id)->lockForUpdate()->first();
        if ($profile === null || $profile->status !== ProfileStatus::Suspended || $dispute->profile_status_before === ProfileStatus::Suspended) {
            return null;
        }

        $released = $this->releaseModerationItems($profile, ModerationHold::ConsentDispute);
        $status = $dispute->profile_status_before === ProfileStatus::PendingReview && $released === 0
            ? ProfileStatus::Draft
            : $dispute->profile_status_before;

        $profile->forceFill(['status' => $status, 'previous_status' => null])->save();

        return $status;
    }
}
