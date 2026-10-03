<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members\Concerns;

use App\Enums\ModerationHold;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared steps of the A03 member actions (suspend / reactivate / delete / restore / purge). Each
 * Action authorizes itself and runs these inside its own transaction.
 */
trait ChangesMemberState
{
    /**
     * The typed reason every A03 write needs (recorded in the audit log, never shown to the member).
     *
     * @throws ValidationException
     */
    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 500 characters (it is recorded in the audit log).')]);
        }

        return $reason;
    }

    /**
     * The member row, locked for this change (deleted ones included). Members only — a broker or
     * any other account is never changed through A03 (brokers have their own A07 screens), even
     * if its id is slipped into a request: that is a 404.
     */
    private function lockMember(User $member): User
    {
        return User::withTrashed()->whereKey($member->id)->where('role', UserRole::Member->value)->lockForUpdate()->firstOrFail();
    }

    private function lockProfile(User $member): ?Profile
    {
        return Profile::withTrashed()->where('user_id', $member->id)->lockForUpdate()->first();
    }

    /**
     * End every session of this member: the session-epoch check signs out each of their sessions
     * on its next request, and rotating the remember token kills "stay logged in" cookies.
     */
    private function revokeSessions(User $member): void
    {
        $member->forceFill([
            'session_epoch' => $member->session_epoch + 1,
            'remember_token' => Str::random(60),
        ]);
    }

    /** Remember the status an admin action is about to replace (only the first one counts). */
    private function rememberStatus(Profile $profile): void
    {
        if (! in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true)) {
            $profile->previous_status = $profile->status;
        }
    }

    /** The status to go back to: the remembered one, never ACTIVE for a profile never approved. */
    private function statusToRestore(Profile $profile): ProfileStatus
    {
        $previous = $profile->previous_status;

        if ($previous !== null && ! in_array($previous, [ProfileStatus::Suspended, ProfileStatus::Deleted], true)) {
            return $previous;
        }

        return $profile->published_at !== null ? ProfileStatus::Active : ProfileStatus::Draft;
    }

    /**
     * Take the member's waiting moderation items out of the queues (A04) while the account is
     * suspended or deleted; the hold says why, so the matching undo can put them back.
     */
    private function holdModerationItems(Profile $profile, ModerationHold $hold): int
    {
        $count = 0;

        // An escalated item is held under its own marker, so the release can put it back to the
        // super admins rather than into the ordinary queue.
        foreach ([ModerationStatus::Open->value => $hold->value, ModerationStatus::Escalated->value => $hold->escalated()] as $status => $category) {
            $count += ModerationItem::query()
                ->where('profile_id', $profile->id)
                ->where('status', $status)
                ->update([
                    'status' => ModerationStatus::Rejected->value,
                    'reason_category' => $category,
                    'decided_by_admin_id' => null,
                    'decided_at' => now(),
                    'claimed_by_admin_id' => null,
                    'claimed_until' => null,
                    'updated_at' => now(),
                ]);
        }

        $this->announceQueues($count);

        return $count;
    }

    /** Put items held for $hold back in the queue (or move them under another hold). */
    private function releaseModerationItems(Profile $profile, ModerationHold $hold, ?ModerationHold $stillHeldAs = null): int
    {
        $count = 0;

        foreach ([$hold->value => [ModerationStatus::Open, $stillHeldAs?->value], $hold->escalated() => [ModerationStatus::Escalated, $stillHeldAs?->escalated()]] as $category => [$status, $stillAs]) {
            $query = ModerationItem::query()
                ->where('profile_id', $profile->id)
                ->where('status', ModerationStatus::Rejected->value)
                ->where('reason_category', $category);

            $count += $stillAs !== null
                ? $query->update(['reason_category' => $stillAs, 'updated_at' => now()])
                : $query->update(['status' => $status->value, 'reason_category' => null, 'decided_at' => null, 'updated_at' => now()]);
        }

        if ($stillHeldAs !== null) {
            return $count;
        }

        $this->announceQueues($count);

        return $count;
    }

    /** Pause the member's running plans (A03 "pauses subscription"). */
    private function pauseSubscriptions(Profile $profile): int
    {
        return Subscription::query()
            ->where('profile_id', $profile->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->where('ends_at', '>', now())
            ->update(['paused_at' => now(), 'updated_at' => now()]);
    }

    /** Resume paused plans: each ends later by exactly the time it was paused. */
    private function resumeSubscriptions(Profile $profile): int
    {
        $resumed = 0;

        Subscription::query()->where('profile_id', $profile->id)->whereNotNull('paused_at')->lockForUpdate()->get()
            ->each(function (Subscription $subscription) use (&$resumed): void {
                $paused = (int) $subscription->paused_at?->diffInSeconds(now(), true);
                $subscription->forceFill([
                    'ends_at' => $subscription->ends_at->copy()->addSeconds($paused),
                    'paused_at' => null,
                ])->save();
                $resumed++;
            });

        return $resumed;
    }

    private function announceQueues(int $changed): void
    {
        if ($changed > 0) {
            foreach (ModerationItemType::cases() as $type) {
                ModerationQueueChanged::dispatch($type);
            }
        }
    }
}
