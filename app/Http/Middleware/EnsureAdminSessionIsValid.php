<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.session` — every signed-in admin request must belong to a live row in admin_sessions
 * (PRD A01): not revoked, idle for less than 30 minutes, and younger than 12 hours absolute.
 * Otherwise the admin is signed out and sent to the login page with the reason.
 */
final class EnsureAdminSessionIsValid
{
    public function __construct(private readonly AdminSessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof AdminUser) {
            return $next($request);
        }

        $session = $this->sessions->current($request);

        if ($session === null || $session->revoked_at !== null) {
            return $this->signOut($request, __('Your session has ended. Please sign in again.'));
        }

        if ($this->isExpired($session)) {
            $this->sessions->revoke($session);

            return $this->signOut($request, __('You were signed out after a period of inactivity. Please sign in again.'));
        }

        $this->sessions->touch($session);

        return $next($request);
    }

    private function isExpired(AdminSession $session): bool
    {
        return $session->last_seen_at->lt(now()->subMinutes(config('oppam.admin.idle_timeout_minutes')))
            || $session->created_at->lt(now()->subMinutes(config('oppam.admin.absolute_timeout_minutes')));
    }

    private function signOut(Request $request, string $message): Response
    {
        auth('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', $message);
    }
}
