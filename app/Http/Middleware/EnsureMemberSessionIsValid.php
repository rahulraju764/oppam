<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Auth\SignInMember;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group, so it guards every member/broker page AND Livewire update
 * request (M01, R-M01-5):
 * - a suspended / banned / deleted account is signed out on its next request;
 * - a session stamped with an older session_epoch ("log out other devices", password reset)
 *   is signed out. A session re-created from a "stay logged in" cookie adopts the current
 *   epoch — those cookies are invalidated separately by rotating the remember token.
 */
final class EnsureMemberSessionIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
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
        auth('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
