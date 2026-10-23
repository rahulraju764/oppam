<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:BROKER` (PRD §8.1): the signed-in web user must have this account type. Any other
 * account gets a 404 — a member never learns the broker portal's pages exist.
 */
final class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || $user->role !== UserRole::tryFrom($role)) {
            abort(404);
        }

        return $next($request);
    }
}
