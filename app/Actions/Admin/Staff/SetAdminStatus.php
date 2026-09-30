<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Domain\Admin\StaffGuardrails;
use App\Enums\AdminStatus;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Suspend or reactivate a staff member (A01). Suspending needs a typed reason, ends every one of
 * their sessions at once, and can't target yourself or the last active super admin.
 * Reactivating also clears a 2FA lock ("unlock"). Either way you must hold every permission of
 * the target's role — a lesser admin can't suspend or unlock a more powerful one.
 */
final class SetAdminStatus
{
    public function __construct(
        private readonly StaffGuardrails $guardrails,
        private readonly AdminSessionRegistry $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function suspend(AdminUser $actor, AdminUser $target, string $reason): void
    {
        Gate::forUser($actor)->authorize('staff.suspend');
        $this->guardrails->assertNotSelf($actor, $target);
        $this->guardrails->assertOutranks($actor, $target);

        DB::transaction(function () use ($actor, $target, $reason): void {
            $this->guardrails->assertNotLastSuperAdmin($target);
            $before = ['status' => $target->status->value];
            $target->forceFill(['status' => AdminStatus::Suspended])->save();
            $ended = $this->sessions->revokeAllFor($target);

            $this->audit->record('staff.suspended', $target, $before, ['status' => AdminStatus::Suspended->value, 'sessions_ended' => $ended], $reason, $actor, $target->email);
        });
    }

    public function reactivate(AdminUser $actor, AdminUser $target, string $reason): void
    {
        Gate::forUser($actor)->authorize('staff.suspend');
        $this->guardrails->assertNotSelf($actor, $target);
        $this->guardrails->assertOutranks($actor, $target);

        $before = ['status' => $target->status->value, 'locked_until' => $target->locked_until?->toIso8601String()];
        $target->forceFill(['status' => AdminStatus::Active, 'locked_until' => null, 'failed_two_factor_attempts' => 0])->save();

        $this->audit->record('staff.reactivated', $target, $before, ['status' => AdminStatus::Active->value, 'locked_until' => null], $reason, $actor, $target->email);
    }
}
