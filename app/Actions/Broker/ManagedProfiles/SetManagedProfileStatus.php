<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\ManagedProfileStatusChange;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The bureau's status actions on a managed client (§11A B.6), each with its own B.2 permission:
 *  - Deactivate: ACTIVE → HIDDEN — out of search like a hidden profile;
 *  - Activate: HIDDEN → ACTIVE, only for a profile A04 already approved (published) — a bureau can
 *    never publish a profile itself (R-M13-14);
 *  - Delete: soft delete with the status it had kept (a DRAFT stays DRAFT: its basics may be
 *    missing, DB CHECK); an open A04 item closes itself as "no longer waiting";
 *  - Restore: within `oppam.broker.managed_restore_days`, back to the kept status — a profile that
 *    was waiting for review comes back as DRAFT and is submitted again.
 * Found inside ManagedProfileScope only (foreign / unassigned → 404); row locked; audited.
 */
final class SetManagedProfileStatus
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile>
     * @throws ManagedProfileRefused
     */
    public function handle(User $actor, string $code, ManagedProfileStatusChange $change): Profile
    {
        $staff = $this->gate->authorize($actor, $change->permission());

        return DB::transaction(function () use ($actor, $staff, $code, $change): Profile {
            $profile = $this->scope->query($staff, withTrashed: $change === ManagedProfileStatusChange::Restore)
                ->where('profiles.code', $code)->lockForUpdate()->firstOrFail();
            $before = ['status' => $profile->status->value, 'deleted' => $profile->trashed()];

            match ($change) {
                ManagedProfileStatusChange::Deactivate => $this->deactivate($profile),
                ManagedProfileStatusChange::Activate => $this->activate($profile),
                ManagedProfileStatusChange::Delete => $this->delete($profile),
                ManagedProfileStatusChange::Restore => $this->restore($profile),
            };

            $this->audit->record('broker.profile_'.$change->value, $profile, $before,
                ['status' => $profile->status->value, 'deleted' => $profile->trashed()], actor: $actor, subjectLabel: $profile->code);

            return $profile;
        });
    }

    private function deactivate(Profile $profile): void
    {
        if ($profile->status !== ProfileStatus::Active) {
            throw ManagedProfileRefused::wrongStatus();
        }

        $profile->forceFill(['status' => ProfileStatus::Hidden, 'previous_status' => ProfileStatus::Active])->save();
    }

    private function activate(Profile $profile): void
    {
        if ($profile->status !== ProfileStatus::Hidden || $profile->published_at === null) {
            throw ManagedProfileRefused::wrongStatus();
        }

        $profile->forceFill(['status' => ProfileStatus::Active, 'previous_status' => null])->save();
    }

    private function delete(Profile $profile): void
    {
        if ($profile->trashed() || in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true)) {
            throw ManagedProfileRefused::wrongStatus();
        }

        $keep = $profile->status;
        $profile->forceFill([
            'previous_status' => $keep,
            'status' => $keep === ProfileStatus::Draft ? ProfileStatus::Draft : ProfileStatus::Deleted,
        ])->save();
        $profile->delete();
    }

    private function restore(Profile $profile): void
    {
        if (! $profile->trashed()) {
            throw ManagedProfileRefused::wrongStatus();
        }

        if ($profile->deleted_at?->lt(now()->subDays((int) config('oppam.broker.managed_restore_days'))) === true) {
            throw ManagedProfileRefused::restoreWindowOver();
        }

        $status = $profile->previous_status ?? ProfileStatus::Draft;
        $profile->forceFill([
            'status' => $status === ProfileStatus::PendingReview ? ProfileStatus::Draft : $status,
            'previous_status' => null,
        ]);
        $profile->restore();
    }
}
