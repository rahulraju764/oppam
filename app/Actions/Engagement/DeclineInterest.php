<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\InterestSignals;
use App\Enums\InterestStatus;
use App\Enums\NotificationEvent;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\InterestNotification;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Decline an interest the member received (PRD M06, F02): only the member it was sent to, only
 * while PENDING. The sender is told politely ("not interested at this time") and cannot re-send
 * to this member for the cooldown (`interest.resend_cooldown_days`, checked by SendInterest).
 * No visibility check: an interest from a profile that has since been hidden can still be cleared.
 * many() declines several at once (the /interests "decline selected").
 */
final class DeclineInterest
{
    public const MAX = 50;

    public function __construct(
        private readonly InterestSignals $signals,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  Profile  $from  the member who sent the interest
     *
     * @throws NotFoundHttpException no interest from that profile to the member
     * @throws EngagementRefused answered, withdrawn or expired meanwhile
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $from): void
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile ?? throw new NotFoundHttpException;

        $this->decline($own, $from);
    }

    /**
     * The decline itself, for a profile whose caller already checked who is acting — the member
     * above, or a bureau for its managed client (RespondToInterestForClient, §11A B.6).
     *
     * @throws NotFoundHttpException no interest from that profile to $own
     * @throws EngagementRefused answered, withdrawn or expired meanwhile
     */
    public function decline(Profile $own, Profile $from): void
    {
        DB::transaction(function () use ($own, $from): void {
            $interest = Interest::query()->where('from_profile_id', $from->id)->where('to_profile_id', $own->id)->lockForUpdate()->first();

            if ($interest === null) {
                throw new NotFoundHttpException;
            }

            if ($interest->status !== InterestStatus::Pending) {
                throw EngagementRefused::notPending();
            }

            $interest->status = InterestStatus::Declined;
            $interest->responded_at = now();
            $interest->save();
            InterestEvent::record($interest, InterestStatus::Declined, $own->id);
        });

        $this->signals->changed($own, $from);

        if ($from->user_id !== null) {
            User::query()->find($from->user_id)?->notify(new InterestNotification(NotificationEvent::InterestDeclined, $own->id));
        }
    }

    /**
     * Decline the pending interests the member received from these profiles (by code); anything
     * else in the list — not a code, not a sender of theirs, no longer pending — is skipped.
     *
     * @param  list<mixed>  $profileCodes
     * @return int how many were declined
     *
     * @throws ImpersonationRestricted
     */
    public function many(User $member, array $profileCodes): int
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        $codes = array_slice(array_values(array_unique(array_filter(
            $profileCodes,
            fn (mixed $code): bool => is_string($code) && preg_match('/^OPM\d{1,10}$/', $code) === 1,
        ))), 0, self::MAX);

        if ($own === null || $codes === []) {
            return 0;
        }

        $senders = Profile::withTrashed()->whereIn('code', $codes)
            ->whereIn('id', Interest::query()->select('from_profile_id')->where('to_profile_id', $own->id)->where('status', InterestStatus::Pending->value))
            ->get();

        $declined = 0;
        foreach ($senders as $sender) {
            try {
                $this->handle($member, $sender);
                $declined++;
            } catch (NotFoundHttpException|EngagementRefused) {
                // answered from another tab meanwhile: skip
            }
        }

        return $declined;
    }
}
