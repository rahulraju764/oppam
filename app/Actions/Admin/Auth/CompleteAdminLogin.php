<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use App\Services\Admin\PendingAdminLogin;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * The last step of admin sign-in, run only after the second factor succeeded (challenge or
 * first-time enrolment): sign in to the admin guard, rotate the session id (no fixation),
 * register the session (A01 session list), reset the 2FA counter and audit the login.
 */
final class CompleteAdminLogin
{
    public function __construct(
        private readonly AdminSessionRegistry $sessions,
        private readonly PendingAdminLogin $pending,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $admin, Request $request): void
    {
        $this->pending->clear();

        auth('admin')->login($admin);
        session()->regenerate();   // new session id: no fixation across the sign-in
        session()->put(EnsureTwoFactorConfirmed::SESSION_KEY, now()->getTimestamp());

        $this->sessions->start($admin, $request);

        $admin->forceFill([
            'failed_two_factor_attempts' => 0,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->record('admin.login', $admin, actor: $admin, subjectLabel: $admin->email);
    }
}
