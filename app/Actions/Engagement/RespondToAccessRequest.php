<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\Connections;
use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\AccessRequest;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\AccessRequestNotification;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The owner answers a photo / horoscope request (PRD M06 "Target approves → requester gets
 * access"): only the member who was asked, only while PENDING. Approved → the requester can see
 * that content (Connections::hasGrant) and is notified; declined → nothing is announced.
 */
final class RespondToAccessRequest
{
    public function __construct(
        private readonly Connections $connections,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  Profile  $requester  the member who asked
     *
     * @throws NotFoundHttpException no such request to the member
     * @throws EngagementRefused already answered
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $requester, AccessRequestType $type, bool $approve): void
    {
        // Opens the member's private photos / horoscope to someone: never on their behalf.
        $this->impersonation->assertAllowed();

        $own = $member->profile ?? throw new NotFoundHttpException;

        DB::transaction(function () use ($own, $requester, $type, $approve): void {
            $request = AccessRequest::query()->where('type', $type->value)
                ->where('requester_profile_id', $requester->id)->where('owner_profile_id', $own->id)->lockForUpdate()->first();

            if ($request === null) {
                throw new NotFoundHttpException;
            }

            if ($request->status !== AccessRequestStatus::Pending) {
                throw EngagementRefused::notPending();
            }

            $request->status = $approve ? AccessRequestStatus::Approved : AccessRequestStatus::Declined;
            $request->responded_at = now();
            $request->save();
        });

        $this->connections->forget();

        if ($approve && $requester->user_id !== null) {
            User::query()->find($requester->user_id)?->notify(new AccessRequestNotification($type, $own->id, approved: true));
        }
    }
}
