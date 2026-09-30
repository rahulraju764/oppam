<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Domain\Admin\StaffGuardrails;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminInvitation;
use App\Models\AdminUser;
use App\Notifications\Admin\AdminInvitationSent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invite a staff member (A01): a single-use link valid for 72 h, emailed to them. Guardrail: you
 * can only invite into a role whose permissions you hold. A newer invitation for the same email
 * revokes the older one. Only the SHA-256 of the token is stored.
 */
final class InviteAdmin
{
    public function __construct(
        private readonly StaffGuardrails $guardrails,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $actor, string $name, string $email, string $roleName): AdminInvitation
    {
        Gate::forUser($actor)->authorize('staff.invite');

        $email = Str::lower(trim($email));
        $role = $this->guardrails->role($roleName);
        $this->guardrails->assertCanGrantRole($actor, $role);

        if (AdminUser::query()->where('email', $email)->exists()) {
            throw GuardrailViolation::alreadyAdmin();
        }

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $name, $email, $role, $token): AdminInvitation {
            AdminInvitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = new AdminInvitation;
            $invitation->forceFill([
                'email' => $email,
                'name' => $name,
                'role' => $role->name,
                'token_hash' => AdminInvitation::hashToken($token),
                'invited_by_id' => $actor->id,
                'expires_at' => now()->addHours(config('oppam.admin.invitation_hours')),
            ])->save();

            $this->audit->record('staff.invited', $invitation, after: ['email' => $email, 'name' => $name, 'role' => $role->name], actor: $actor, subjectLabel: $email);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new AdminInvitationSent(
            url: route('admin.invitations.accept', ['token' => $token]),
            invitedBy: $actor->name,
            expiresAt: $invitation->expires_at,
        ));

        return $invitation;
    }
}
