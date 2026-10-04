<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Admin\Impersonation\EndImpersonation;
use App\Actions\Auth\SignInMember;
use App\Enums\ImpersonationEndReason;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group, so it guards every member/broker page AND Livewire update
 * request (M01, R-M01-5):
 * - a suspended / banned / deleted account is signed out on its next request;
 * - a session stamped with an older session_epoch ("log out other devices", password reset)
 *   is signed out. A session re-created from a "stay logged in" cookie adopts the current
 *   epoch — those cookies are invalidated separately by rotating the remember token.
 *
 * Signing out uses logoutCurrentDevice(): it ends THIS session only and never rotates the
 * remember token (every revocation path rotates it itself; rotating here again would undo the
 * cookie "log out other devices" just re-issued to the member's own device). A session that is
 * an admin impersonation (P1.7b) also has its impersonation row closed (REVOKED, audited). The
 * impersonation handoff route is left alone: it replaces whatever session the browser had.
 */
final class EnsureMemberSessionIsValid
{
    public function __construct(
        private readonly EndImpersonation $endImpersonation,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || $request->routeIs('impersonation.enter')) {
            return $next($request);
        }

        if (! $user->isActive()) {
            return $this->signOut($request, __('This account is not active. Please contact our support team for help.'));
        }

        $stamped = $request->session()->get(SignInMember::EPOCH_SESSION_KEY);

        if ($stamped === null) {
            $request->session()->put(SignInMember::EPOCH_SESSION_KEY, $user->session_epoch);
        } elseif ((int) $stamped !== $user->session_epoch) {
            return $this->signOut($request, __('You were signed out because your account was logged out on all devices. Please log in again.'));
        }

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        $impersonationId = $request->session()->get(Impersonation::SESSION_KEY);
        if (is_string($impersonationId)) {
            $row = ImpersonationSession::query()->whereKey($impersonationId)->first();
            if ($row !== null) {
                $this->endImpersonation->handle($row, ImpersonationEndReason::Revoked);
            }
        }

        $guard = auth('web');
        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->impersonation->forget();

        return redirect()->route('login')->with('status', $message);
    }
}
