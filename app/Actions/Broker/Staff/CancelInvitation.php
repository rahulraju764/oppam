<?php

declare(strict_types=1);

namespace App\Actions\Broker\Staff;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Models\BrokerStaffInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\ValueObjects\PhoneNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Owner withdraws an open invitation (§11A B.8): its link stops working and its seat frees at
 * once. Only the caller's own bureau's open invitations (else 404). Audited.
 */
final class CancelInvitation
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without `staff.manage`
     * @throws ModelNotFoundException<BrokerStaffInvitation>
     */
    public function handle(User $owner, string $invitationId): void
    {
        $staff = $this->gate->authorize($owner, BureauPermission::StaffManage);

        $invitation = BrokerStaffInvitation::query()->where('broker_id', $staff->broker_id)->whereKey($invitationId)
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->firstOrFail();

        $invitation->forceFill(['expires_at' => now()])->save();

        $this->audit->record('broker.staff_invite_cancelled', $invitation, after: ['broker' => $staff->broker?->code],
            actor: $owner, subjectLabel: PhoneNumber::tryParse($invitation->phone)?->masked());
    }
}
