<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `profile.onboarded` (PRD §13) — member pages other than the wizard need a submitted profile:
 * a DRAFT (or missing) profile is sent to /onboarding until the wizard is submitted (R-M02-2).
 * Broker logins own no matrimony profile (§8.1) and have no member pages.
 */
final class EnsureProfileOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->role !== UserRole::Member) {
            abort(404);
        }

        $status = $user->profile?->status;

        if ($status === null || $status === ProfileStatus::Draft) {
            return redirect()->route('member.onboarding', ['step' => 1]);
        }

        return $next($request);
    }
}
