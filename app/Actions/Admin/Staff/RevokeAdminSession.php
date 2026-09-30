<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;

/**
 * End an admin session (A01 "session list & revoke"): your own any time; anyone else's only with
 * sessions.revoke_any. The owner is signed out on their next request (EnsureAdminSessionIsValid).
 */
final class RevokeAdminSession
{
    public function __construct(
        private readonly AdminSessionRegistry $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $actor, AdminSession $session): void
    {
        if ($session->admin_user_id !== $actor->id) {
            Gate::forUser($actor)->authorize('sessions.revoke_any');
        }

        $this->sessions->revoke($session);

        $this->audit->record('staff.session_revoked', $session, actor: $actor, subjectLabel: $session->admin?->email);
    }
}
