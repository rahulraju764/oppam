<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.active` — a suspended or locked admin is signed out on their very next request, even
 * mid-session (A01).
 */
final class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof AdminUser && (! $admin->isActive() || $admin->isLocked())) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->with('status', __('Your account is not active. Contact a super admin.'));
        }

        return $next($request);
    }
}
