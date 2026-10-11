<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\InterestSignals;
use App\Enums\InterestStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Withdraw an interest the member SENT (PRD M06 state machine: WITHDRAWN by the sender): only
 * the sender, only while PENDING. The month's interest is not given back (owner decision
 * 2026-10-07 — like an expiry), and nobody is notified: it just leaves the other member's
 * "Received" list (their open page is told live). No visibility check, so an interest to a
 * profile that has since been hidden can still be taken back.
 */
final class WithdrawInterest
{
    public function __construct(
        private readonly InterestSignals $signals,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  Profile  $to  the member the interest was sent to
     *
     * @throws NotFoundHttpException no interest from the member to that profile
     * @throws EngagementRefused answered or expired meanwhile
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $to): void
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile ?? throw new NotFoundHttpException;

        DB::transaction(function () use ($own, $to): void {
            $interest = Interest::query()->where('from_profile_id', $own->id)->where('to_profile_id', $to->id)->lockForUpdate()->first();

            if ($interest === null) {
                throw new NotFoundHttpException;
            }

            if ($interest->status !== InterestStatus::Pending) {
                throw EngagementRefused::notPending();
            }

            $interest->status = InterestStatus::Withdrawn;
            $interest->responded_at = now();
            $interest->save();
            InterestEvent::record($interest, InterestStatus::Withdrawn, $own->id);
        });

        $this->signals->changed($own, $to);
    }
}
