<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Actions\Admin\Verification\Concerns\DecidesVerificationRequests;
use App\Domain\Verification\VerificationQueue;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Events\Verification\VerificationChanged;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\VerificationRequest;
use App\Notifications\Verification\VerificationRejectedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reject an ID (A05 "reasons → member guidance"): a reason is required, the officer's note (≤ 500,
 * required for "Other") is shown to the member below the reason's guidance. The member may send a
 * new document at once. Audited; the member's page updates live and they are notified.
 */
final class RejectVerification
{
    use DecidesVerificationRequests;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VerificationQueue $queue,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws VerificationRequestUnavailable
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, VerificationRequest $request, VerificationRejectReason $reason, ?string $note = null): void
    {
        $this->authorizeDecision($admin, $request);
        $note = $this->validatedNote($note, $reason === VerificationRejectReason::Other);
        $this->holdClaim($admin, $request);

        $profile = DB::transaction(function () use ($admin, $request, $reason, $note): Profile {
            $profile = $this->lockForDecision($admin, $request);
            $before = ['status' => $request->status->value];

            $this->decide($request, $admin, VerificationStatus::Rejected, ['reject_reason' => $reason, 'member_note' => $note]);

            $this->audit->record('verification.rejected', $request, $before,
                ['status' => VerificationStatus::Rejected->value, 'reason' => $reason->value], reason: $note, actor: $admin, subjectLabel: $profile->code);

            if ($profile->user_id !== null) {
                VerificationChanged::dispatch($profile->user_id, VerificationStatus::Rejected->value, $profile->is_verified);
            }
            $this->queue->announceCount();

            return $profile;
        });

        $profile->loadMissing('user')->user?->notify(new VerificationRejectedNotification((string) $request->memberGuidance()));
    }
}
