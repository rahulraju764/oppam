<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Events\Broker\ProfileClaimed;
use App\Exceptions\Broker\ClaimUnavailable;
use App\Models\BrokerReferral;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Broker\ManagedProfileClaimed;
use App\Services\Audit\AuditLogger;
use App\ValueObjects\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The claim flow (§11A B.6, R-M13-15 / R-M13-16, F08): a client who registers with the SAME
 * mobile a bureau stored for them, and proves it by OTP, is asked "Is this you?" — and only an
 * explicit "Yes" links the profile; never a silent link. The candidate is always found from the
 * signed-in user's own verified number, never from an id or code in the request.
 *
 * Offered (decisions 2026-10-25) only while the new account's own profile is still the untouched
 * registration DRAFT (nothing saved in the wizard), and only for an unclaimed BROKER_MANAGED
 * profile that isn't deleted (B.19 "deleted, then client tries to claim → not offered").
 *
 * claim(), in one transaction with the managed profile's row locked — the same lock an export
 * takes, so no export can slip in once the claim commits (B.19 "export racing a claim"):
 *  - the registration placeholder (and its referral row — the bureau's MANAGED row stays,
 *    first-touch, R-M13-3) is removed;
 *  - the managed profile becomes the member's: user_id, owner_type SELF, claimed_at; the bureau
 *    link, the assigned staff and the stored client mobile are cleared (old values kept in the
 *    audit row, the mobile masked). `broker_id` stays: commission still flows (B.6);
 *  - after commit, ProfileClaimed on `broker.{broker}` and an in-app note to the Owner and the
 *    assignee. From then on ManagedProfileScope (unclaimed only) gives the bureau 404 everywhere.
 */
final class ClaimManagedProfile
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The profile this member may claim right now, or null (nothing to offer). */
    public function candidateFor(User $user): ?Profile
    {
        if (! $this->placeholderOf($user) instanceof Profile) {
            return null;
        }

        return $this->claimable($user)->first();
    }

    /**
     * "Yes, this is me".
     *
     * @throws ClaimUnavailable nothing (any more) to claim for this member
     */
    public function claim(User $user): Profile
    {
        [$profile, $brokerId, $assignedStaffId] = DB::transaction(function () use ($user): array {
            $placeholder = $this->placeholderOf($user, lock: true) ?? throw ClaimUnavailable::nothingToClaim();
            $profile = $this->claimable($user)->lockForUpdate()->first() ?? throw ClaimUnavailable::nothingToClaim();

            $brokerId = (string) $profile->managed_by_broker_id;
            $before = [
                'managed_by_broker_id' => $brokerId,
                'assigned_staff_id' => $profile->assigned_staff_id,
                'client_phone' => $profile->client_phone !== null ? PhoneNumber::fromE164($profile->client_phone)->masked() : null,
                'owner_type' => $profile->owner_type->value,
            ];
            $assignedStaffId = $profile->assigned_staff_id;

            BrokerReferral::query()->where('profile_id', $placeholder->id)->delete();
            $placeholder->forceDelete();

            $profile->forceFill([
                'user_id' => $user->id,
                'owner_type' => ProfileOwnerType::Self,
                'claimed_at' => now(),
                'managed_by_broker_id' => null,
                'assigned_staff_id' => null,
                'client_phone' => null,
            ])->save();

            $this->audit->record('broker.profile_claimed', $profile, $before, [
                'owner_type' => ProfileOwnerType::Self->value, 'claimed' => true,
            ], actor: $user, subjectLabel: $profile->code);

            return [$profile, $brokerId, $assignedStaffId];
        });

        ProfileClaimed::dispatch($brokerId, $profile->code);
        $this->notifyBureau($brokerId, $assignedStaffId, $profile->code);

        $user->unsetRelation('profile');

        return $profile;
    }

    /** "Is this you?" card text: first name + last initial and the code (never the full name). */
    public static function maskedName(Profile $profile): string
    {
        $initial = $profile->last_name !== null && $profile->last_name !== '' ? ' '.mb_substr($profile->last_name, 0, 1).'.' : '';

        return $profile->first_name.$initial;
    }

    /** @return Builder<Profile> unclaimed, not deleted BROKER_MANAGED profiles stored with the member's verified mobile */
    private function claimable(User $user): Builder
    {
        return Profile::query()
            ->where('client_phone', $user->phone)
            ->where('owner_type', ProfileOwnerType::BrokerManaged->value)
            ->whereNotNull('managed_by_broker_id')
            ->whereNull('claimed_at')
            ->whereNull('user_id')
            ->where('status', '!=', ProfileStatus::Deleted->value);
    }

    /** The member's own registration DRAFT, while nothing has been saved in the wizard yet. */
    private function placeholderOf(User $user, bool $lock = false): ?Profile
    {
        if ($user->role !== UserRole::Member || ! $user->isActive() || ! $user->hasVerifiedPhone()) {
            return null;
        }

        return Profile::query()
            ->where('user_id', $user->id)
            ->where('status', ProfileStatus::Draft->value)
            ->where('owner_type', ProfileOwnerType::Self->value)
            ->where('completeness', 0)
            ->when($lock, fn (Builder $q) => $q->lockForUpdate())
            ->first();
    }

    private function notifyBureau(string $brokerId, ?string $assignedStaffId, string $code): void
    {
        $notification = new ManagedProfileClaimed($code);

        BrokerStaff::query()
            ->where('broker_id', $brokerId)
            ->where('status', BureauStaffStatus::Active)
            ->where(fn ($q) => $q->where('role', BureauRole::Owner)
                ->when($assignedStaffId !== null, fn ($x) => $x->orWhere('id', $assignedStaffId)))
            ->with('user')
            ->get()
            ->each(fn (BrokerStaff $staff) => $staff->user?->isActive() === true ? $staff->user->notify($notification) : null);
    }
}
