<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `verified.phone` (PRD §13) — member pages need a verified mobile. Sign-in only ever happens
 * after a code (SignInMember), so an unverified signed-in account means something went wrong:
 * sign it out rather than let it through.
 */
final class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user instanceof User && ! $user->hasVerifiedPhone()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
