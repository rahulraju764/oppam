<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Actions\Admin\Verification\Concerns\DecidesVerificationRequests;
use App\Domain\Verification\VerificationQueue;
use App\Enums\VerificationStatus;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escalate a request to the super admins (A05): PENDING → ESCALATED with an internal note (5–500,
 * never shown to the member). The claim is released; from now on only a super admin decides it.
 */
final class EscalateVerification
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
    public function handle(AdminUser $admin, VerificationRequest $request, ?string $note): void
    {
        $this->authorizeDecision($admin, $request);
        $note = (string) $this->validatedNote($note, true, 'escalationNote');

        if ($request->status === VerificationStatus::Escalated) {
            throw VerificationRequestUnavailable::alreadyDecided();
        }

        $this->holdClaim($admin, $request);

        DB::transaction(function () use ($admin, $request, $note): void {
            $profile = $this->lockForDecision($admin, $request);

            $request->forceFill([
                'status' => VerificationStatus::Escalated,
                'escalation_note' => $note,
                'claimed_by_admin_id' => null,
                'claimed_until' => null,
            ])->save();

            $this->audit->record('verification.escalated', $request, ['status' => VerificationStatus::Pending->value],
                ['status' => VerificationStatus::Escalated->value], reason: $note, actor: $admin, subjectLabel: $profile->code);
            $this->queue->announceClaim();
        });
    }
}
