<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminInvitation;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Accept a staff invitation (A01): the token must be unused, unrevoked and within 72 h. Creates
 * the admin with the invited role; they then sign in and are forced to enrol 2FA. The invitation
 * is consumed in the same transaction, so a link works exactly once.
 */
final class AcceptAdminInvitation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function findUsable(string $token): ?AdminInvitation
    {
        $invitation = AdminInvitation::query()->where('token_hash', AdminInvitation::hashToken($token))->first();

        return $invitation?->isUsable() ? $invitation : null;
    }

    public function handle(string $token, string $password): AdminUser
    {
        return DB::transaction(function () use ($token, $password): AdminUser {
            $invitation = AdminInvitation::query()
                ->where('token_hash', AdminInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isUsable()) {
                throw new AdminAuthenticationFailed(__('This invitation link is invalid or has expired. Ask for a new one.'));
            }

            if (AdminUser::query()->where('email', $invitation->email)->exists()) {
                throw GuardrailViolation::alreadyAdmin();
            }

            $admin = new AdminUser;
            $admin->forceFill([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $password,
                'invited_by_id' => $invitation->invited_by_id,
            ])->save();
            $admin->assignRole($invitation->role);

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record('staff.invitation_accepted', $admin, after: ['role' => $invitation->role], actor: $admin, subjectLabel: $admin->email);

            return $admin;
        });
    }
}
