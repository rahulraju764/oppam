<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Actions\Admin\Verification\Concerns\DecidesVerificationRequests;
use App\Domain\Verification\VerificationQueue;
use App\Enums\VerificationStatus;
use App\Events\Verification\VerificationChanged;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\VerificationRequest;
use App\Notifications\Verification\VerificationApprovedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Approve an ID (A05, F05): the request is APPROVED, the profile gets the Verified badge
 * (`is_verified` — search badge and the "verified only" filter, M09), the member's open pages
 * update live (`.verification.changed`) and they are notified. Audited.
 */
final class ApproveVerification
{
    use DecidesVerificationRequests;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VerificationQueue $queue,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws VerificationRequestUnavailable
     */
    public function handle(AdminUser $admin, VerificationRequest $request): void
    {
        $this->authorizeDecision($admin, $request);
        $this->holdClaim($admin, $request);

        $profile = DB::transaction(function () use ($admin, $request): Profile {
            $profile = $this->lockForDecision($admin, $request);
            $before = ['status' => $request->status->value, 'is_verified' => $profile->is_verified];

            $this->decide($request, $admin, VerificationStatus::Approved);
            $profile->forceFill(['is_verified' => true])->save();

            $this->audit->record('verification.approved', $request, $before,
                ['status' => VerificationStatus::Approved->value, 'is_verified' => true], actor: $admin, subjectLabel: $profile->code);

            if ($profile->user_id !== null) {
                VerificationChanged::dispatch($profile->user_id, VerificationStatus::Approved->value, true);
            }
            $this->queue->announceCount();

            return $profile;
        });

        $profile->loadMissing('user')->user?->notify(new VerificationApprovedNotification);
    }
}
