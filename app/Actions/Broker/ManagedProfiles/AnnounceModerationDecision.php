<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\Import\BatchModerationTally;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Events\Broker\ManagedProfileModerated;
use App\Models\BrokerImportBatch;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Notifications\Broker\ManagedProfileReviewed;

/**
 * Tell the bureau that A04 decided one of its managed profiles (§11A B.15), called by
 * ApproveProfile / RejectProfile after their commit. Live `profile.moderated` on `broker.{broker}`
 * (code + decision only), and ManagedProfileReviewed (with the reason) to the Owner, Managers, the
 * creator and the assignee — each an ACTIVE login of that bureau with the profile inside their
 * ManagedProfileScope (an assigned-only creator who was re-assigned away hears nothing). Does
 * nothing for a member's own profile.
 */
final class AnnounceModerationDecision
{
    public function __construct(
        private readonly ManagedProfileScope $scope,
        private readonly BatchModerationTally $tally,
    ) {}

    public function handle(Profile $profile, bool $approved, ?string $reason = null): void
    {
        if (! $profile->isBrokerManaged()) {
            return;
        }

        // An imported profile: its batch's approval rate, and the auto-pause check (R-M13-32).
        if ($profile->import_batch_id !== null) {
            $batch = BrokerImportBatch::query()->find($profile->import_batch_id);
            if ($batch !== null) {
                $this->tally->refresh($batch);
            }
        }

        $brokerId = (string) $profile->managed_by_broker_id;
        ManagedProfileModerated::dispatch($brokerId, $profile->code, $approved ? ManagedProfileModerated::APPROVED : ManagedProfileModerated::REJECTED);

        $notification = new ManagedProfileReviewed($profile->code, $approved, $approved ? null : $reason);

        BrokerStaff::query()
            ->where('broker_id', $brokerId)
            ->where('status', BureauStaffStatus::Active)
            ->where(fn ($query) => $query->whereIn('role', [BureauRole::Owner, BureauRole::Manager])
                ->when($profile->created_by_user_id !== null, fn ($q) => $q->orWhere('user_id', $profile->created_by_user_id))
                ->when($profile->assigned_staff_id !== null, fn ($q) => $q->orWhere('id', $profile->assigned_staff_id)))
            ->with('user')
            ->get()
            ->filter(fn (BrokerStaff $staff): bool => $staff->user?->isActive() === true && $this->scope->covers($staff, $profile))
            ->each(fn (BrokerStaff $staff) => $staff->user?->notify($notification));
    }
}
